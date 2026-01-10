<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Students Directory | AcademixSuite School Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');
        
        :root {
            --school-primary: #4f46e5;
            --school-secondary: #10b981;
            --school-surface: #ffffff;
            --school-bg: #f8fafc;
        }

        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--school-bg); 
            color: #1e293b; 
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Glassmorphism effects */
        .glass-header { 
            background: rgba(255, 255, 255, 0.92); 
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(226, 232, 240, 0.3);
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(226, 232, 240, 0.5);
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.04);
        }

        /* Toast Notification Styles */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 400px;
        }
        
        .toast {
            padding: 16px 20px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            animation: slideIn 0.3s ease forwards;
            opacity: 0;
            transform: translateX(100px);
            transition: opacity 0.3s, transform 0.3s;
        }
        
        .toast-success {
            background: linear-gradient(135deg, #10b981, #34d399);
            color: white;
            border-left: 4px solid #059669;
        }
        
        .toast-info {
            background: linear-gradient(135deg, #4f46e5, #7c73e9);
            color: white;
            border-left: 4px solid #3730a3;
        }
        
        .toast-warning {
            background: linear-gradient(135deg, #f59e0b, #fbbf24);
            color: white;
            border-left: 4px solid #d97706;
        }
        
        .toast-error {
            background: linear-gradient(135deg, #ef4444, #f87171);
            color: white;
            border-left: 4px solid #dc2626;
        }
        
        @keyframes slideIn {
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes fadeOut {
            to {
                opacity: 0;
                transform: translateX(100px);
            }
        }
        
        .toast-exit {
            animation: fadeOut 0.3s ease forwards;
        }
        
        .toast-icon {
            font-size: 18px;
            flex-shrink: 0;
        }
        
        .toast-content {
            flex: 1;
        }
        
        .toast-close {
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.8);
            font-size: 14px;
            cursor: pointer;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background-color 0.2s;
        }
        
        .toast-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        /* Sidebar styling */
        .sidebar-link { 
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); 
            border-left: 3px solid transparent; 
            position: relative;
        }
        
        .sidebar-link:hover { 
            background: linear-gradient(90deg, rgba(79, 70, 229, 0.05) 0%, rgba(79, 70, 229, 0.02) 100%);
            color: var(--school-primary); 
            border-left-color: rgba(79, 70, 229, 0.3);
        }
        
        .active-link { 
            background: linear-gradient(90deg, rgba(79, 70, 229, 0.1) 0%, rgba(79, 70, 229, 0.05) 100%);
            color: var(--school-primary); 
            border-left-color: var(--school-primary); 
            font-weight: 700;
        }
        
        .active-link::before {
            content: '';
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 60%;
            background: var(--school-primary);
            border-radius: 4px 0 0 4px;
        }

        /* Custom scrollbar */
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Animation for cards */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-fadeInUp {
            animation: fadeInUp 0.6s ease-out forwards;
        }

        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .status-active {
            background-color: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        
        .status-inactive {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        
        .status-graduated {
            background-color: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        /* Grade badges */
        .grade-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 900;
            text-transform: uppercase;
        }
        
        .grade-a {
            background: linear-gradient(135deg, #10b981, #34d399);
            color: white;
        }
        
        .grade-b {
            background: linear-gradient(135deg, #3b82f6, #60a5fa);
            color: white;
        }
        
        .grade-c {
            background: linear-gradient(135deg, #f59e0b, #fbbf24);
            color: white;
        }
        
        .grade-d {
            background: linear-gradient(135deg, #ef4444, #f87171);
            color: white;
        }
        
        .grade-f {
            background: linear-gradient(135deg, #6b7280, #9ca3af);
            color: white;
        }

        /* Mobile optimizations */
        @media (max-width: 768px) {
            .glass-header {
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
                background: white;
            }
            
            .toast-container {
                left: 20px;
                right: 20px;
                max-width: none;
            }
            
            .chart-container {
                height: 250px !important;
            }
        }

        /* Tab styling */
        .tab-button {
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 600;
            color: #64748b;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .tab-button:hover {
            color: #4f46e5;
        }
        
        .tab-button.active {
            color: #4f46e5;
            border-bottom-color: #4f46e5;
            background: linear-gradient(to top, rgba(79, 70, 229, 0.05), transparent);
        }

        /* Metric Cards */
        .metric-card {
            position: relative;
            overflow: hidden;
        }
        
        .metric-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--metric-color), transparent);
        }
        
        .metric-primary {
            --metric-color: #4f46e5;
        }
        
        .metric-success {
            --metric-color: #10b981;
        }
        
        .metric-warning {
            --metric-color: #f59e0b;
        }
        
        .metric-danger {
            --metric-color: #ef4444;
        }

        /* Chart Containers */
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        /* Student Cards */
        .student-card {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .student-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.08);
        }
        
        /* Avatar Styles */
        .avatar {
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-weight: 600;
            color: white;
        }
        
        .avatar-sm {
            width: 32px;
            height: 32px;
            font-size: 12px;
        }
        
        .avatar-md {
            width: 40px;
            height: 40px;
            font-size: 14px;
        }
        
        .avatar-lg {
            width: 48px;
            height: 48px;
            font-size: 16px;
        }
        
        .avatar-xl {
            width: 64px;
            height: 64px;
            font-size: 20px;
        }
        
        /* Badge Styles */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .badge-primary {
            background-color: #e0e7ff;
            color: #3730a3;
        }
        
        .badge-success {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .badge-warning {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .badge-danger {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .badge-info {
            background-color: #dbeafe;
            color: #1e40af;
        }

        /* Form Styles */
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
        }
        
        .form-input {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .form-select {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            background: white;
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 12px center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
        }

        /* Action Buttons */
        .action-btn {
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s ease;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .action-btn-primary {
            background: linear-gradient(135deg, #4f46e5, #7c73e9);
            color: white;
        }
        
        .action-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(79, 70, 229, 0.3);
        }
        
        .action-btn-secondary {
            background: white;
            color: #4f46e5;
            border: 1px solid #e2e8f0;
        }
        
        .action-btn-secondary:hover {
            background: #f8fafc;
            border-color: #4f46e5;
        }
        
        .action-btn-danger {
            background: linear-gradient(135deg, #ef4444, #f87171);
            color: white;
        }
        
        .action-btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.3);
        }

        /* Search and Filter */
        .search-box {
            position: relative;
            width: 100%;
        }
        
        .search-input {
            width: 100%;
            padding: 12px 16px 12px 44px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .search-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        /* Filter Chips */
        .filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .filter-chip:hover {
            background: #e2e8f0;
        }
        
        .filter-chip.active {
            background: #4f46e5;
            color: white;
            border-color: #4f46e5;
        }

        /* Pagination */
        .pagination-btn {
            padding: 8px 12px;
            background: white;
            border: 1px solid #e2e8f0;
            color: #64748b;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .pagination-btn:hover {
            background: #f8fafc;
            border-color: #4f46e5;
            color: #4f46e5;
        }
        
        .pagination-btn.active {
            background: #4f46e5;
            color: white;
            border-color: #4f46e5;
        }
        
        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Progress bars */
        .progress-bar {
            height: 6px;
            border-radius: 3px;
            background: #f1f5f9;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s ease;
        }
        
        .progress-primary {
            background: linear-gradient(90deg, #4f46e5, #7c73e9);
        }
        
        .progress-success {
            background: linear-gradient(90deg, #10b981, #34d399);
        }
        
        .progress-warning {
            background: linear-gradient(90deg, #f59e0b, #fbbf24);
        }
        
        .progress-danger {
            background: linear-gradient(90deg, #ef4444, #f87171);
        }

        /* Table Styles */
        .table-container {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th {
            background: #f8fafc;
            padding: 16px;
            text-align: left;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .data-table td {
            padding: 16px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        
        .data-table tr:hover {
            background: #f8fafc;
        }
        
        .data-table tr:last-child td {
            border-bottom: none;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 20px;
        }
        
        .modal-content {
            background: white;
            border-radius: 16px;
            max-width: 800px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }

        /* Student Profile */
        .profile-header {
            background: linear-gradient(135deg, #4f46e5, #7c73e9);
            color: white;
            padding: 32px;
            border-radius: 16px 16px 0 0;
        }
        
        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            border: 4px solid white;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            font-weight: 700;
            color: #4f46e5;
        }

        /* Attendance Indicator */
        .attendance-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 4px;
        }
        
        .attendance-present {
            background: #10b981;
        }
        
        .attendance-absent {
            background: #ef4444;
        }
        
        .attendance-late {
            background: #f59e0b;
        }
        
        .attendance-excused {
            background: #8b5cf6;
        }
    </style>
</head>
<body class="antialiased selection:bg-indigo-100 selection:text-indigo-900">

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-[99] lg:hidden hidden" onclick="mobileSidebarToggle()"></div>

    <!-- Add Student Modal -->
    <div id="addStudentModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="modal-content">
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-black text-slate-900">Add New Student</h3>
                    <button onclick="closeModal('addStudentModal')" class="text-slate-400 hover:text-slate-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <form id="addStudentForm" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="form-label">First Name *</label>
                            <input type="text" name="firstName" class="form-input" placeholder="John" required>
                        </div>
                        <div>
                            <label class="form-label">Last Name *</label>
                            <input type="text" name="lastName" class="form-input" placeholder="Doe" required>
                        </div>
                        <div>
                            <label class="form-label">Date of Birth *</label>
                            <input type="date" name="dateOfBirth" class="form-input" required>
                        </div>
                        <div>
                            <label class="form-label">Gender *</label>
                            <select name="gender" class="form-select" required>
                                <option value="">Select Gender</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Grade Level *</label>
                            <select name="gradeLevel" class="form-select" required>
                                <option value="">Select Grade</option>
                                <option value="k">Kindergarten</option>
                                <option value="1">Grade 1</option>
                                <option value="2">Grade 2</option>
                                <option value="3">Grade 3</option>
                                <option value="4">Grade 4</option>
                                <option value="5">Grade 5</option>
                                <option value="6">Grade 6</option>
                                <option value="7">Grade 7</option>
                                <option value="8">Grade 8</option>
                                <option value="9">Grade 9</option>
                                <option value="10">Grade 10</option>
                                <option value="11">Grade 11</option>
                                <option value="12">Grade 12</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Section *</label>
                            <select name="section" class="form-select" required>
                                <option value="">Select Section</option>
                                <option value="A">Section A</option>
                                <option value="B">Section B</option>
                                <option value="C">Section C</option>
                                <option value="D">Section D</option>
                                <option value="E">Section E</option>
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="form-label">Student ID *</label>
                            <input type="text" name="studentId" class="form-input" placeholder="STU-2024-001" required>
                        </div>
                        <div class="md:col-span-2">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-input" placeholder="student@school.edu">
                        </div>
                        <div>
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone" class="form-input" placeholder="+1 (555) 123-4567">
                        </div>
                        <div>
                            <label class="form-label">Enrollment Date *</label>
                            <input type="date" name="enrollmentDate" class="form-input" required>
                        </div>
                    </div>
                    
                    <div class="border-t border-slate-100 pt-6">
                        <h4 class="text-lg font-bold text-slate-900 mb-4">Parent/Guardian Information</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="form-label">Parent Name *</label>
                                <input type="text" name="parentName" class="form-input" placeholder="Jane Doe" required>
                            </div>
                            <div>
                                <label class="form-label">Parent Relationship *</label>
                                <select name="parentRelationship" class="form-select" required>
                                    <option value="">Select Relationship</option>
                                    <option value="mother">Mother</option>
                                    <option value="father">Father</option>
                                    <option value="guardian">Guardian</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">Parent Email *</label>
                                <input type="email" name="parentEmail" class="form-input" placeholder="parent@email.com" required>
                            </div>
                            <div>
                                <label class="form-label">Parent Phone *</label>
                                <input type="tel" name="parentPhone" class="form-input" placeholder="+1 (555) 123-4567" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="border-t border-slate-100 pt-6">
                        <h4 class="text-lg font-bold text-slate-900 mb-4">Emergency Contact</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="form-label">Emergency Contact Name</label>
                                <input type="text" name="emergencyContact" class="form-input" placeholder="Emergency Contact">
                            </div>
                            <div>
                                <label class="form-label">Emergency Contact Phone</label>
                                <input type="tel" name="emergencyPhone" class="form-input" placeholder="+1 (555) 987-6543">
                            </div>
                        </div>
                    </div>
                    
                    <div class="border-t border-slate-100 pt-6">
                        <h4 class="text-lg font-bold text-slate-900 mb-4">Medical Information</h4>
                        <div class="space-y-4">
                            <div>
                                <label class="form-label">Medical Conditions</label>
                                <textarea name="medicalConditions" class="form-input h-24" placeholder="List any medical conditions or allergies"></textarea>
                            </div>
                            <div>
                                <label class="form-label">Medications</label>
                                <textarea name="medications" class="form-input h-24" placeholder="List any current medications"></textarea>
                            </div>
                            <div>
                                <label class="form-label">Special Requirements</label>
                                <textarea name="specialRequirements" class="form-input h-24" placeholder="Any special educational or physical requirements"></textarea>
                            </div>
                        </div>
                    </div>
                </form>
                
                <div class="flex gap-3 mt-8 pt-6 border-t border-slate-100">
                    <button onclick="closeModal('addStudentModal')" class="flex-1 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                        Cancel
                    </button>
                    <button onclick="saveNewStudent()" class="flex-1 py-3 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-emerald-200">
                        Add Student
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Student Modal -->
    <div id="viewStudentModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="modal-content" style="max-width: 1000px;">
            <div class="profile-header">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="profile-avatar" id="studentAvatar">
                            JD
                        </div>
                        <div>
                            <h3 class="text-2xl font-black text-white" id="studentName">John Doe</h3>
                            <div class="flex items-center gap-4 mt-2">
                                <span class="text-white/80" id="studentInfo">Grade 10 • Section A • ID: STU-2024-001</span>
                                <span class="status-badge status-active" id="studentStatus">Active</span>
                            </div>
                        </div>
                    </div>
                    <button onclick="closeModal('viewStudentModal')" class="text-white/80 hover:text-white">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>
            
            <div class="p-8">
                <div class="flex items-center gap-4 mb-6">
                    <button onclick="switchStudentTab('overview')" class="tab-button active" data-tab="overview">Overview</button>
                    <button onclick="switchStudentTab('academics')" class="tab-button" data-tab="academics">Academics</button>
                    <button onclick="switchStudentTab('attendance')" class="tab-button" data-tab="attendance">Attendance</button>
                    <button onclick="switchStudentTab('documents')" class="tab-button" data-tab="documents">Documents</button>
                    <button onclick="switchStudentTab('notes')" class="tab-button" data-tab="notes">Notes</button>
                </div>
                
                <!-- Overview Tab -->
                <div id="studentOverviewTab" class="student-tab-content">
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <div class="lg:col-span-2 space-y-6">
                            <!-- Personal Information -->
                            <div class="glass-card rounded-xl p-6">
                                <h4 class="text-lg font-black text-slate-900 mb-4">Personal Information</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <p class="text-sm text-slate-500">Date of Birth</p>
                                        <p class="font-bold text-slate-900" id="studentDOB">January 15, 2008</p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-slate-500">Gender</p>
                                        <p class="font-bold text-slate-900" id="studentGender">Male</p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-slate-500">Age</p>
                                        <p class="font-bold text-slate-900" id="studentAge">16 years</p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-slate-500">Enrollment Date</p>
                                        <p class="font-bold text-slate-900" id="studentEnrollment">September 1, 2024</p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Contact Information -->
                            <div class="glass-card rounded-xl p-6">
                                <h4 class="text-lg font-black text-slate-900 mb-4">Contact Information</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <p class="text-sm text-slate-500">Email Address</p>
                                        <p class="font-bold text-slate-900" id="studentEmail">john.doe@school.edu</p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-slate-500">Phone Number</p>
                                        <p class="font-bold text-slate-900" id="studentPhone">+1 (555) 123-4567</p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-slate-500">Address</p>
                                        <p class="font-bold text-slate-900" id="studentAddress">123 Main St, Anytown, USA</p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Parent/Guardian Information -->
                            <div class="glass-card rounded-xl p-6">
                                <h4 class="text-lg font-black text-slate-900 mb-4">Parent/Guardian Information</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <p class="text-sm text-slate-500">Parent Name</p>
                                        <p class="font-bold text-slate-900" id="studentParent">Jane Doe</p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-slate-500">Relationship</p>
                                        <p class="font-bold text-slate-900" id="studentParentRel">Mother</p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-slate-500">Parent Email</p>
                                        <p class="font-bold text-slate-900" id="studentParentEmail">jane.doe@email.com</p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-slate-500">Parent Phone</p>
                                        <p class="font-bold text-slate-900" id="studentParentPhone">+1 (555) 987-6543</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Stats -->
                        <div class="space-y-6">
                            <div class="glass-card rounded-xl p-6">
                                <h4 class="text-lg font-black text-slate-900 mb-4">Quick Stats</h4>
                                <div class="space-y-4">
                                    <div>
                                        <p class="text-sm text-slate-500">Overall GPA</p>
                                        <p class="text-2xl font-black text-slate-900">3.75</p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-slate-500">Attendance Rate</p>
                                        <p class="text-2xl font-black text-emerald-600">94.2%</p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-slate-500">Current Grade</p>
                                        <div class="grade-badge grade-a inline-flex">A</div>
                                        <span class="ml-2 font-bold text-slate-900">Mathematics</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Emergency Contact -->
                            <div class="glass-card rounded-xl p-6">
                                <h4 class="text-lg font-black text-slate-900 mb-4">Emergency Contact</h4>
                                <div class="space-y-2">
                                    <p class="font-bold text-slate-900" id="studentEmergencyContact">John Smith</p>
                                    <p class="text-slate-600" id="studentEmergencyPhone">+1 (555) 456-7890</p>
                                    <p class="text-sm text-slate-500">Uncle</p>
                                </div>
                            </div>
                            
                            <!-- Medical Information -->
                            <div class="glass-card rounded-xl p-6">
                                <h4 class="text-lg font-black text-slate-900 mb-4">Medical Information</h4>
                                <div class="space-y-2">
                                    <p class="text-sm text-slate-600" id="studentMedical">No known medical conditions</p>
                                    <p class="text-sm text-slate-600" id="studentAllergies">Peanut allergy</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Academics Tab -->
                <div id="studentAcademicsTab" class="student-tab-content hidden">
                    <div class="glass-card rounded-xl p-6">
                        <h4 class="text-lg font-black text-slate-900 mb-6">Academic Performance</h4>
                        <div class="chart-container mb-6">
                            <canvas id="academicPerformanceChart"></canvas>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>Teacher</th>
                                        <th>Current Grade</th>
                                        <th>Mid-term</th>
                                        <th>Final</th>
                                        <th>Attendance</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="academicSubjects">
                                    <!-- Academic subjects will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Attendance Tab -->
                <div id="studentAttendanceTab" class="student-tab-content hidden">
                    <div class="glass-card rounded-xl p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h4 class="text-lg font-black text-slate-900">Attendance Record</h4>
                            <div class="flex items-center gap-4">
                                <span class="text-sm text-slate-500">Current Month: <span class="font-bold text-emerald-600">94.2%</span></span>
                                <span class="text-sm text-slate-500">Year-to-date: <span class="font-bold text-slate-900">92.8%</span></span>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <div>
                                <h5 class="font-bold text-slate-900 mb-4">Monthly Overview</h5>
                                <div class="chart-container">
                                    <canvas id="attendanceChart"></canvas>
                                </div>
                            </div>
                            
                            <div>
                                <h5 class="font-bold text-slate-900 mb-4">Recent Attendance</h5>
                                <div class="space-y-3">
                                    <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
                                        <div>
                                            <p class="font-medium text-slate-900">Today</p>
                                            <p class="text-sm text-slate-500">December 4, 2024</p>
                                        </div>
                                        <span class="status-badge status-active">Present</span>
                                    </div>
                                    <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
                                        <div>
                                            <p class="font-medium text-slate-900">Yesterday</p>
                                            <p class="text-sm text-slate-500">December 3, 2024</p>
                                        </div>
                                        <span class="status-badge status-active">Present</span>
                                    </div>
                                    <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
                                        <div>
                                            <p class="font-medium text-slate-900">November 28</p>
                                            <p class="text-sm text-slate-500">Last Week</p>
                                        </div>
                                        <span class="status-badge status-inactive">Absent</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="p-8 border-t border-slate-100">
                <div class="flex gap-3">
                    <button onclick="editStudent()" class="action-btn action-btn-primary">
                        <i class="fas fa-edit"></i> Edit Student
                    </button>
                    <button onclick="printStudentRecord()" class="action-btn action-btn-secondary">
                        <i class="fas fa-print"></i> Print Record
                    </button>
                    <button onclick="sendParentMessage()" class="action-btn action-btn-secondary">
                        <i class="fas fa-envelope"></i> Message Parent
                    </button>
                    <button onclick="transferStudent()" class="action-btn action-btn-secondary">
                        <i class="fas fa-exchange-alt"></i> Transfer
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Import Modal -->
    <div id="bulkImportModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="modal-content" style="max-width: 600px;">
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-black text-slate-900">Bulk Import Students</h3>
                    <button onclick="closeModal('bulkImportModal')" class="text-slate-400 hover:text-slate-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <div class="space-y-6">
                    <div class="border-2 border-dashed border-slate-200 rounded-xl p-8 text-center">
                        <i class="fas fa-file-excel text-4xl text-emerald-500 mb-3"></i>
                        <p class="text-slate-600 mb-2">Upload CSV or Excel file</p>
                        <p class="text-sm text-slate-500 mb-4">Download template and fill in student information</p>
                        <button onclick="downloadTemplate()" class="action-btn action-btn-secondary mr-3">
                            <i class="fas fa-download"></i> Download Template
                        </button>
                        <button onclick="uploadFile()" class="action-btn action-btn-primary">
                            <i class="fas fa-upload"></i> Upload File
                        </button>
                    </div>
                    
                    <div>
                        <h4 class="font-bold text-slate-900 mb-3">Import Guidelines</h4>
                        <ul class="space-y-2 text-sm text-slate-600">
                            <li class="flex items-start gap-2">
                                <i class="fas fa-check text-emerald-500 mt-1"></i>
                                <span>Use the provided template for correct formatting</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <i class="fas fa-check text-emerald-500 mt-1"></i>
                                <span>Maximum file size: 10MB</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <i class="fas fa-check text-emerald-500 mt-1"></i>
                                <span>Supported formats: .csv, .xlsx, .xls</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <i class="fas fa-check text-emerald-500 mt-1"></i>
                                <span>Required fields: First Name, Last Name, Grade, Section</span>
                            </li>
                        </ul>
                    </div>
                </div>
                
                <div class="flex gap-3 mt-8 pt-6 border-t border-slate-100">
                    <button onclick="closeModal('bulkImportModal')" class="flex-1 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                        Cancel
                    </button>
                    <button onclick="processImport()" class="flex-1 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-indigo-200">
                        Process Import
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="flex h-screen overflow-hidden">
        
        <aside id="sidebar" class="fixed inset-y-0 left-0 w-64 bg-white border-r border-slate-200 z-[100] lg:relative lg:translate-x-0 -translate-x-full transition-transform duration-300 flex flex-col shadow-xl lg:shadow-none">
            
            <!-- School Header -->
            <div class="h-20 flex items-center px-6 border-b border-slate-100">
                <div class="flex items-center gap-3">
                    <div class="relative">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-600 to-purple-600 flex items-center justify-center shadow-lg shadow-indigo-100">
                            <i class="fas fa-school text-white text-lg"></i>
                        </div>
                        <div class="absolute -bottom-1 -right-1 w-4 h-4 bg-emerald-500 border-2 border-white rounded-full"></div>
                    </div>
                    <div>
                        <span class="text-xl font-black tracking-tight text-slate-900">Greenwood High</span>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">SCHOOL ADMIN</p>
                    </div>
                </div>
            </div>

            <!-- School Quick Info -->
            <div class="p-6 border-b border-slate-100">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-600">Total Students:</span>
                        <span class="text-sm font-black text-indigo-600">1,248</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-600">Active Today:</span>
                        <span class="text-sm font-bold text-emerald-600">1,176</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-600">New This Month:</span>
                        <span class="text-sm font-bold text-slate-900">24</span>
                    </div>
                </div>
            </div>

            <!-- Navigation -->
            <div class="flex-1 overflow-y-auto py-6 space-y-8 custom-scrollbar">
                <div>
                    <p class="px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">Dashboard</p>
                    <nav class="space-y-1">
                        <a href="school-dashboard.html" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-chart-pie"></i>
                            </div>
                            <span>Overview</span>
                        </a>
                        <a href="school-announcements.html" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-bullhorn"></i>
                            </div>
                            <span>Announcements</span>
                        </a>
                    </nav>
                </div>

                <div>
                    <p class="px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">Student Management</p>
                    <nav class="space-y-1">
                        <a href="school-students.html" class="sidebar-link active-link flex items-center gap-3 px-6 py-3 text-sm font-semibold">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-user-graduate"></i>
                            </div>
                            <span>Students Directory</span>
                            <span class="text-xs font-bold text-slate-400 ml-auto">1,248</span>
                        </a>
                        <a href="school-attendance.html" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <span>Attendance</span>
                        </a>
                        <a href="school-grades.html" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-chart-bar"></i>
                            </div>
                            <span>Grades & Reports</span>
                        </a>
                    </nav>
                </div>

                <div>
                    <p class="px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">Staff Management</p>
                    <nav class="space-y-1">
                        <a href="school-teachers.html" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-chalkboard-teacher"></i>
                            </div>
                            <span>Teachers</span>
                        </a>
                        <a href="school-schedule.html" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <span>Timetable</span>
                        </a>
                    </nav>
                </div>

                <div>
                    <p class="px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">School Operations</p>
                    <nav class="space-y-1">
                        <a href="school-fees.html" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-receipt"></i>
                            </div>
                            <span>Fee Management</span>
                        </a>
                        <a href="school-settings.html" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-cog"></i>
                            </div>
                            <span>School Settings</span>
                        </a>
                    </nav>
                </div>
            </div>

            <!-- User Profile -->
            <div class="p-6 border-t border-slate-100">
                <div class="flex items-center gap-3 p-2 group cursor-pointer hover:bg-slate-50 rounded-xl transition">
                    <div class="relative">
                        <img src="https://ui-avatars.com/api/?name=Principal+Smith&background=4f46e5&color=fff&bold=true&size=128" class="w-10 h-10 rounded-xl shadow-sm">
                        <div class="absolute -bottom-1 -right-1 w-3.5 h-3.5 bg-emerald-500 border-2 border-white rounded-full"></div>
                    </div>
                    <div class="overflow-hidden flex-1">
                        <p class="text-[13px] font-black text-slate-900 truncate">Principal Smith</p>
                        <p class="text-[10px] font-black text-indigo-600 uppercase tracking-wider italic">School_Admin</p>
                    </div>
                </div>
            </div>
        </aside>

        <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
            
            <!-- Header -->
            <header class="h-20 glass-header px-6 lg:px-8 flex items-center justify-between shrink-0 z-40">
                <div class="flex items-center gap-3">
                    <button onclick="mobileSidebarToggle()" class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg transition">
                        <i class="fas fa-bars-staggered"></i>
                    </button>
                    <div class="flex items-center gap-3">
                        <h1 class="text-lg font-black text-slate-900 tracking-tight">Students Directory</h1>
                        <div class="hidden lg:flex items-center gap-2">
                            <div class="w-2 h-2 bg-emerald-500 rounded-full"></div>
                            <span class="text-xs font-black text-emerald-600 uppercase tracking-widest">1,248 Total Students</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <!-- Quick Stats -->
                    <div class="hidden md:flex items-center gap-2 bg-white border border-slate-200 px-4 py-2 rounded-xl">
                        <div class="text-right">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Today's Absent</p>
                            <p class="text-sm font-black text-red-600">24</p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-2">
                        <button onclick="openModal('bulkImportModal')" class="px-4 py-2 bg-white border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2">
                            <i class="fas fa-file-import"></i>
                            <span class="hidden sm:inline">Import</span>
                        </button>
                        <button onclick="generateReports()" class="px-4 py-2 bg-white border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2">
                            <i class="fas fa-file-export"></i>
                            <span class="hidden sm:inline">Export</span>
                        </button>
                        <button onclick="openModal('addStudentModal')" class="px-4 py-2 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-emerald-200">
                            <i class="fas fa-user-plus"></i>
                            <span class="hidden sm:inline">Add Student</span>
                        </button>
                    </div>
                </div>
            </header>

            <!-- Tabs Navigation -->
            <div class="border-b border-slate-200 bg-white">
                <div class="max-w-7xl mx-auto px-6 lg:px-8">
                    <div class="flex overflow-x-auto">
                        <button class="tab-button active" onclick="switchTab('all')" data-tab="all">
                            <i class="fas fa-list mr-2"></i>All Students
                        </button>
                        <button class="tab-button" onclick="switchTab('active')" data-tab="active">
                            <i class="fas fa-user-check mr-2"></i>Active
                        </button>
                        <button class="tab-button" onclick="switchTab('new')" data-tab="new">
                            <i class="fas fa-star mr-2"></i>New Admissions
                        </button>
                        <button class="tab-button" onclick="switchTab('graduating')" data-tab="graduating">
                            <i class="fas fa-graduation-cap mr-2"></i>Graduating
                        </button>
                        <button class="tab-button" onclick="switchTab('inactive')" data-tab="inactive">
                            <i class="fas fa-user-slash mr-2"></i>Inactive
                        </button>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="flex-1 overflow-y-auto p-6 lg:p-8 custom-scrollbar">
                <!-- Page Header & Filters -->
                <div class="max-w-7xl mx-auto mb-8">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                        <div>
                            <h2 class="text-2xl lg:text-3xl font-black text-slate-900 mb-2">Student Management System</h2>
                            <p class="text-slate-500 font-medium">Manage student records, admissions, and academic information</p>
                        </div>
                        <div class="flex gap-3">
                            <div class="search-box">
                                <input type="text" placeholder="Search students by name, ID, or grade..." class="search-input" id="searchInput" onkeyup="filterStudents()">
                                <i class="fas fa-search search-icon"></i>
                            </div>
                            <button onclick="toggleAdvancedFilters()" class="px-4 py-2.5 bg-white border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2">
                                <i class="fas fa-sliders-h"></i>
                                <span class="hidden sm:inline">Filters</span>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Advanced Filters -->
                    <div class="glass-card rounded-xl p-6 mt-6 hidden" id="advancedFilters">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-black text-slate-900">Advanced Filters</h3>
                            <button onclick="toggleAdvancedFilters()" class="text-slate-400 hover:text-slate-600">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                            <div>
                                <label class="form-label">Grade Level</label>
                                <select class="form-select" id="filterGrade" onchange="applyFilters()">
                                    <option value="">All Grades</option>
                                    <option value="k">Kindergarten</option>
                                    <option value="1">Grade 1</option>
                                    <option value="2">Grade 2</option>
                                    <option value="3">Grade 3</option>
                                    <option value="4">Grade 4</option>
                                    <option value="5">Grade 5</option>
                                    <option value="6">Grade 6</option>
                                    <option value="7">Grade 7</option>
                                    <option value="8">Grade 8</option>
                                    <option value="9">Grade 9</option>
                                    <option value="10">Grade 10</option>
                                    <option value="11">Grade 11</option>
                                    <option value="12">Grade 12</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="form-label">Section</label>
                                <select class="form-select" id="filterSection" onchange="applyFilters()">
                                    <option value="">All Sections</option>
                                    <option value="A">Section A</option>
                                    <option value="B">Section B</option>
                                    <option value="C">Section C</option>
                                    <option value="D">Section D</option>
                                    <option value="E">Section E</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="form-label">Gender</label>
                                <select class="form-select" id="filterGender" onchange="applyFilters()">
                                    <option value="">All Genders</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="form-label">Status</label>
                                <select class="form-select" id="filterStatus" onchange="applyFilters()">
                                    <option value="">All Status</option>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="pending">Pending</option>
                                    <option value="graduated">Graduated</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="flex justify-between items-center mt-6 pt-6 border-t border-slate-100">
                            <button onclick="clearFilters()" class="px-4 py-2 text-slate-600 hover:text-slate-800 transition">
                                Clear All Filters
                            </button>
                            <button onclick="toggleAdvancedFilters()" class="px-6 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-indigo-200">
                                Apply Filters
                            </button>
                        </div>
                    </div>
                    
                    <!-- Filter Chips -->
                    <div class="flex flex-wrap gap-2 mt-6">
                        <span class="filter-chip active" onclick="toggleFilter('all')" data-filter="all">
                            <i class="fas fa-users"></i> All Students (1,248)
                        </span>
                        <span class="filter-chip" onclick="toggleFilter('grade10')" data-filter="grade10">
                            <i class="fas fa-graduation-cap"></i> Grade 10 (186)
                        </span>
                        <span class="filter-chip" onclick="toggleFilter('grade11')" data-filter="grade11">
                            <i class="fas fa-graduation-cap"></i> Grade 11 (192)
                        </span>
                        <span class="filter-chip" onclick="toggleFilter('grade12')" data-filter="grade12">
                            <i class="fas fa-graduation-cap"></i> Grade 12 (178)
                        </span>
                        <span class="filter-chip" onclick="toggleFilter('absent')" data-filter="absent">
                            <i class="fas fa-user-slash"></i> Absent Today (24)
                        </span>
                        <span class="filter-chip" onclick="toggleFilter('fees')" data-filter="fees">
                            <i class="fas fa-exclamation-circle"></i> Fee Due (45)
                        </span>
                    </div>
                </div>

                <!-- Key Metrics -->
                <div class="max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Total Students Card -->
                    <div class="glass-card metric-card metric-primary rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.1s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">TOTAL STUDENTS</p>
                                <p class="text-2xl font-black text-slate-900">1,248</p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-50 to-purple-50 flex items-center justify-center">
                                <i class="fas fa-user-graduate text-indigo-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="text-emerald-600 font-bold"><i class="fas fa-arrow-up mr-1"></i> 4.2%</span>
                            <span class="text-slate-500">from last year</span>
                        </div>
                    </div>
                    
                    <!-- Attendance Rate Card -->
                    <div class="glass-card metric-card metric-success rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.2s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">ATTENDANCE RATE</p>
                                <p class="text-2xl font-black text-slate-900">94.2%</p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-50 to-emerald-100 flex items-center justify-center">
                                <i class="fas fa-calendar-check text-emerald-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="text-emerald-600 font-bold"><i class="fas fa-arrow-up mr-1"></i> 1.8%</span>
                            <span class="text-slate-500">from last week</span>
                        </div>
                    </div>
                    
                    <!-- Average GPA Card -->
                    <div class="glass-card metric-card metric-warning rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.3s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">AVERAGE GPA</p>
                                <p class="text-2xl font-black text-slate-900">3.42</p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-50 to-amber-100 flex items-center justify-center">
                                <i class="fas fa-chart-line text-amber-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="text-emerald-600 font-bold"><i class="fas fa-arrow-up mr-1"></i> 0.15</span>
                            <span class="text-slate-500">from last semester</span>
                        </div>
                    </div>
                    
                    <!-- New Admissions Card -->
                    <div class="glass-card metric-card metric-danger rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.4s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">NEW ADMISSIONS</p>
                                <p class="text-2xl font-black text-slate-900">24</p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-red-50 to-red-100 flex items-center justify-center">
                                <i class="fas fa-user-plus text-red-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="text-red-600 font-bold"><i class="fas fa-arrow-down mr-1"></i> 3</span>
                            <span class="text-slate-500">from last month</span>
                        </div>
                    </div>
                </div>

                <!-- Grade Distribution Chart -->
                <div class="max-w-7xl mx-auto glass-card rounded-2xl p-6 mb-8">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h3 class="text-lg font-black text-slate-900">Student Distribution by Grade</h3>
                            <p class="text-slate-500">Number of students across different grade levels</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <select class="form-select text-sm" onchange="updateGradeChart()">
                                <option>Current Academic Year</option>
                                <option>2023-2024</option>
                                <option>2022-2023</option>
                                <option>2021-2022</option>
                            </select>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="gradeDistributionChart"></canvas>
                    </div>
                </div>

                <!-- Students Table -->
                <div class="max-w-7xl mx-auto glass-card rounded-2xl overflow-hidden">
                    <div class="p-6 border-b border-slate-100">
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                            <h3 class="text-lg font-black text-slate-900" id="studentsTitle">All Students</h3>
                            <div class="flex items-center gap-3">
                                <span class="text-sm text-slate-500" id="studentsCount">Showing 1-20 of 1,248 students</span>
                                <div class="flex items-center gap-2">
                                    <button onclick="previousPage()" class="pagination-btn">
                                        <i class="fas fa-chevron-left"></i>
                                    </button>
                                    <button class="pagination-btn active">1</button>
                                    <button onclick="nextPage()" class="pagination-btn">2</button>
                                    <button onclick="nextPage()" class="pagination-btn">3</button>
                                    <span class="text-slate-400">...</span>
                                    <button onclick="nextPage()" class="pagination-btn">62</button>
                                    <button onclick="nextPage()" class="pagination-btn">
                                        <i class="fas fa-chevron-right"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th class="w-12">
                                        <input type="checkbox" class="rounded border-slate-300" id="selectAll">
                                    </th>
                                    <th>Student</th>
                                    <th>Grade & Section</th>
                                    <th>Parent/Guardian</th>
                                    <th>Contact</th>
                                    <th>Status</th>
                                    <th>Attendance</th>
                                    <th>GPA</th>
                                    <th class="w-24">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="studentsTableBody">
                                <!-- Students will be loaded here -->
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="p-6 border-t border-slate-100">
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                            <div class="flex items-center gap-4">
                                <select class="form-select text-sm w-32" onchange="changePageSize(this.value)">
                                    <option value="20">20 per page</option>
                                    <option value="50">50 per page</option>
                                    <option value="100">100 per page</option>
                                    <option value="250">250 per page</option>
                                </select>
                                <span class="text-sm text-slate-500" id="selectedCount">0 students selected</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <button onclick="bulkActions()" class="action-btn action-btn-secondary" id="bulkActionsBtn" disabled>
                                    <i class="fas fa-layer-group"></i> Bulk Actions
                                </button>
                                <button onclick="printStudentList()" class="action-btn action-btn-secondary">
                                    <i class="fas fa-print"></i> Print List
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions & Statistics -->
                <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-3 gap-6 mt-8">
                    <!-- Quick Actions -->
                    <div class="glass-card rounded-2xl p-6">
                        <h3 class="text-lg font-black text-slate-900 mb-6">Quick Actions</h3>
                        <div class="space-y-3">
                            <button onclick="takeAttendance()" class="w-full p-4 border border-slate-200 rounded-xl text-left hover:bg-slate-50 transition">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center">
                                        <i class="fas fa-calendar-check text-amber-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-bold text-slate-900">Take Attendance</p>
                                        <p class="text-sm text-slate-500">Record today's student attendance</p>
                                    </div>
                                </div>
                            </button>
                            
                            <button onclick="generateReportCards()" class="w-full p-4 border border-slate-200 rounded-xl text-left hover:bg-slate-50 transition">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
                                        <i class="fas fa-file-alt text-blue-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-bold text-slate-900">Generate Report Cards</p>
                                        <p class="text-sm text-slate-500">Create end-of-term reports</p>
                                    </div>
                                </div>
                            </button>
                            
                            <button onclick="sendParentNotifications()" class="w-full p-4 border border-slate-200 rounded-xl text-left hover:bg-slate-50 transition">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center">
                                        <i class="fas fa-bell text-emerald-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-bold text-slate-900">Send Parent Notifications</p>
                                        <p class="text-sm text-slate-500">Notify parents about updates</p>
                                    </div>
                                </div>
                            </button>
                            
                            <button onclick="manageClassLists()" class="w-full p-4 border border-slate-200 rounded-xl text-left hover:bg-slate-50 transition">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center">
                                        <i class="fas fa-users text-purple-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-bold text-slate-900">Manage Class Lists</p>
                                        <p class="text-sm text-slate-500">Update class assignments</p>
                                    </div>
                                </div>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Recent Admissions -->
                    <div class="glass-card rounded-2xl p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-black text-slate-900">Recent Admissions</h3>
                            <button onclick="viewAllAdmissions()" class="text-indigo-600 hover:text-indigo-700 text-sm font-bold">
                                View All
                            </button>
                        </div>
                        
                        <div class="space-y-4">
                            <div class="flex items-center gap-3">
                                <div class="avatar avatar-md bg-gradient-to-br from-indigo-500 to-purple-500">
                                    JD
                                </div>
                                <div class="flex-1">
                                    <p class="font-medium text-slate-900">John Doe</p>
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-slate-500">Grade 10 • Section A</span>
                                        <span class="badge badge-primary">New</span>
                                    </div>
                                </div>
                                <span class="text-xs text-slate-500">2 days ago</span>
                            </div>
                            
                            <div class="flex items-center gap-3">
                                <div class="avatar avatar-md bg-gradient-to-br from-emerald-500 to-teal-500">
                                    SJ
                                </div>
                                <div class="flex-1">
                                    <p class="font-medium text-slate-900">Sarah Johnson</p>
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-slate-500">Grade 11 • Section B</span>
                                        <span class="badge badge-primary">New</span>
                                    </div>
                                </div>
                                <span class="text-xs text-slate-500">3 days ago</span>
                            </div>
                            
                            <div class="flex items-center gap-3">
                                <div class="avatar avatar-md bg-gradient-to-br from-amber-500 to-orange-500">
                                    MB
                                </div>
                                <div class="flex-1">
                                    <p class="font-medium text-slate-900">Michael Brown</p>
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-slate-500">Grade 9 • Section C</span>
                                        <span class="badge badge-primary">New</span>
                                    </div>
                                </div>
                                <span class="text-xs text-slate-500">5 days ago</span>
                            </div>
                            
                            <div class="flex items-center gap-3">
                                <div class="avatar avatar-md bg-gradient-to-br from-blue-500 to-cyan-500">
                                    EW
                                </div>
                                <div class="flex-1">
                                    <p class="font-medium text-slate-900">Emma Wilson</p>
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-slate-500">Grade 12 • Section A</span>
                                        <span class="badge badge-primary">Transfer</span>
                                    </div>
                                </div>
                                <span class="text-xs text-slate-500">1 week ago</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Upcoming Events -->
                    <div class="glass-card rounded-2xl p-6">
                        <h3 class="text-lg font-black text-slate-900 mb-6">Upcoming Student Events</h3>
                        <div class="space-y-4">
                            <div class="flex items-center gap-4 p-3 bg-blue-50 border border-blue-100 rounded-xl">
                                <div class="flex-shrink-0 w-12 h-12 rounded-lg bg-blue-100 flex flex-col items-center justify-center">
                                    <span class="text-sm font-black text-blue-600">DEC</span>
                                    <span class="text-lg font-black text-blue-600">15</span>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-bold text-slate-900 mb-1">Science Fair</h4>
                                    <p class="text-xs text-slate-500">Grades 6-12 • Science Dept</p>
                                </div>
                            </div>
                            
                            <div class="flex items-center gap-4 p-3 bg-emerald-50 border border-emerald-100 rounded-xl">
                                <div class="flex-shrink-0 w-12 h-12 rounded-lg bg-emerald-100 flex flex-col items-center justify-center">
                                    <span class="text-sm font-black text-emerald-600">DEC</span>
                                    <span class="text-lg font-black text-emerald-600">18</span>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-bold text-slate-900 mb-1">Sports Day</h4>
                                    <p class="text-xs text-slate-500">All grades • Sports Field</p>
                                </div>
                            </div>
                            
                            <div class="flex items-center gap-4 p-3 bg-purple-50 border border-purple-100 rounded-xl">
                                <div class="flex-shrink-0 w-12 h-12 rounded-lg bg-purple-100 flex flex-col items-center justify-center">
                                    <span class="text-sm font-black text-purple-600">JAN</span>
                                    <span class="text-lg font-black text-purple-600">10</span>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-bold text-slate-900 mb-1">Parent-Teacher Meetings</h4>
                                    <p class="text-xs text-slate-500">All parents • Main Hall</p>
                                </div>
                            </div>
                            
                            <div class="flex items-center gap-4 p-3 bg-amber-50 border border-amber-100 rounded-xl">
                                <div class="flex-shrink-0 w-12 h-12 rounded-lg bg-amber-100 flex flex-col items-center justify-center">
                                    <span class="text-sm font-black text-amber-600">JAN</span>
                                    <span class="text-lg font-black text-amber-600">15</span>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-bold text-slate-900 mb-1">Mid-term Exams Begin</h4>
                                    <p class="text-xs text-slate-500">All grades • Exam Halls</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Toast Notification System
        class Toast {
            static show(message, type = 'info', duration = 5000) {
                const container = document.getElementById('toastContainer');
                const toast = document.createElement('div');
                toast.className = `toast toast-${type}`;
                
                const icons = {
                    success: 'fa-check-circle',
                    info: 'fa-info-circle',
                    warning: 'fa-exclamation-triangle',
                    error: 'fa-times-circle'
                };
                
                toast.innerHTML = `
                    <i class="fas ${icons[type]} toast-icon"></i>
                    <div class="toast-content">${message}</div>
                    <button class="toast-close" onclick="this.parentElement.remove()">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                
                container.appendChild(toast);
                
                setTimeout(() => {
                    toast.style.opacity = '1';
                    toast.style.transform = 'translateX(0)';
                }, 10);
                
                if (duration > 0) {
                    setTimeout(() => {
                        toast.classList.add('toast-exit');
                        setTimeout(() => {
                            if (toast.parentNode) {
                                toast.remove();
                            }
                        }, 300);
                    }, duration);
                }
                
                return toast;
            }
            
            static success(message, duration = 5000) {
                return this.show(message, 'success', duration);
            }
            
            static info(message, duration = 5000) {
                return this.show(message, 'info', duration);
            }
            
            static warning(message, duration = 5000) {
                return this.show(message, 'warning', duration);
            }
            
            static error(message, duration = 5000) {
                return this.show(message, 'error', duration);
            }
        }

        // Sample Students Data
        const studentsData = [
            {
                id: 1,
                firstName: "John",
                lastName: "Doe",
                studentId: "STU-2024-001",
                grade: "10",
                section: "A",
                dateOfBirth: "2008-01-15",
                gender: "male",
                email: "john.doe@school.edu",
                phone: "+1 (555) 123-4567",
                address: "123 Main St, Anytown, USA",
                enrollmentDate: "2024-09-01",
                status: "active",
                gpa: 3.75,
                attendance: 94.2,
                parentName: "Jane Doe",
                parentRelationship: "mother",
                parentEmail: "jane.doe@email.com",
                parentPhone: "+1 (555) 987-6543",
                emergencyContact: "John Smith",
                emergencyPhone: "+1 (555) 456-7890",
                medicalConditions: "None",
                allergies: "Peanut allergy",
                avatarColor: "from-indigo-500 to-purple-500"
            },
            {
                id: 2,
                firstName: "Sarah",
                lastName: "Johnson",
                studentId: "STU-2024-002",
                grade: "11",
                section: "B",
                dateOfBirth: "2007-03-22",
                gender: "female",
                email: "sarah.j@school.edu",
                phone: "+1 (555) 234-5678",
                address: "456 Oak Ave, Anytown, USA",
                enrollmentDate: "2023-09-01",
                status: "active",
                gpa: 3.92,
                attendance: 96.5,
                parentName: "Robert Johnson",
                parentRelationship: "father",
                parentEmail: "robert.j@email.com",
                parentPhone: "+1 (555) 876-5432",
                emergencyContact: "Mary Johnson",
                emergencyPhone: "+1 (555) 765-4321",
                medicalConditions: "Asthma",
                allergies: "None",
                avatarColor: "from-emerald-500 to-teal-500"
            },
            {
                id: 3,
                firstName: "Michael",
                lastName: "Brown",
                studentId: "STU-2024-003",
                grade: "9",
                section: "C",
                dateOfBirth: "2009-07-10",
                gender: "male",
                email: "michael.b@school.edu",
                phone: "+1 (555) 345-6789",
                address: "789 Pine Rd, Anytown, USA",
                enrollmentDate: "2024-09-01",
                status: "active",
                gpa: 3.25,
                attendance: 88.7,
                parentName: "Lisa Brown",
                parentRelationship: "mother",
                parentEmail: "lisa.b@email.com",
                parentPhone: "+1 (555) 765-4321",
                emergencyContact: "David Brown",
                emergencyPhone: "+1 (555) 654-3210",
                medicalConditions: "None",
                allergies: "Dairy",
                avatarColor: "from-amber-500 to-orange-500"
            },
            {
                id: 4,
                firstName: "Emma",
                lastName: "Wilson",
                studentId: "STU-2024-004",
                grade: "12",
                section: "A",
                dateOfBirth: "2006-11-30",
                gender: "female",
                email: "emma.w@school.edu",
                phone: "+1 (555) 456-7890",
                address: "321 Elm St, Anytown, USA",
                enrollmentDate: "2020-09-01",
                status: "active",
                gpa: 4.0,
                attendance: 98.9,
                parentName: "James Wilson",
                parentRelationship: "father",
                parentEmail: "james.w@email.com",
                parentPhone: "+1 (555) 543-2109",
                emergencyContact: "Sarah Wilson",
                emergencyPhone: "+1 (555) 432-1098",
                medicalConditions: "None",
                allergies: "None",
                avatarColor: "from-blue-500 to-cyan-500"
            },
            {
                id: 5,
                firstName: "Alex",
                lastName: "Garcia",
                studentId: "STU-2024-005",
                grade: "10",
                section: "B",
                dateOfBirth: "2008-05-18",
                gender: "male",
                email: "alex.g@school.edu",
                phone: "+1 (555) 567-8901",
                address: "654 Maple Dr, Anytown, USA",
                enrollmentDate: "2024-09-01",
                status: "active",
                gpa: 3.45,
                attendance: 92.3,
                parentName: "Maria Garcia",
                parentRelationship: "mother",
                parentEmail: "maria.g@email.com",
                parentPhone: "+1 (555) 432-1098",
                emergencyContact: "Carlos Garcia",
                emergencyPhone: "+1 (555) 321-0987",
                medicalConditions: "ADHD",
                allergies: "None",
                avatarColor: "from-red-500 to-pink-500"
            }
        ];

        // State Management
        let currentTab = 'all';
        let selectedStudents = new Set();
        let currentPage = 1;
        let itemsPerPage = 20;
        let gradeChart = null;
        let academicChart = null;
        let attendanceChart = null;

        // Initialize page
        function initializePage() {
            loadStudentsTable();
            initializeCharts();
            setupEventListeners();
        }

        // Load students table
        function loadStudentsTable() {
            const tableBody = document.getElementById('studentsTableBody');
            const filteredData = filterStudentsData();
            
            tableBody.innerHTML = '';
            
            if (filteredData.length === 0) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="9" class="text-center py-12">
                            <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-user-graduate text-slate-400 text-2xl"></i>
                            </div>
                            <h3 class="text-lg font-bold text-slate-900 mb-2">No students found</h3>
                            <p class="text-slate-500 mb-6">Try adjusting your filters or add a new student</p>
                            <button onclick="openModal('addStudentModal')" class="action-btn action-btn-primary">
                                <i class="fas fa-plus"></i> Add Student
                            </button>
                        </td>
                    </tr>
                `;
                return;
            }
            
            // Calculate pagination
            const startIndex = (currentPage - 1) * itemsPerPage;
            const endIndex = startIndex + itemsPerPage;
            const paginatedData = filteredData.slice(startIndex, endIndex);
            
            // Render students
            paginatedData.forEach(student => {
                const row = createStudentRow(student);
                tableBody.appendChild(row);
            });
            
            // Update pagination info
            updatePaginationInfo(filteredData.length);
        }

        // Create student row
        function createStudentRow(student) {
            const row = document.createElement('tr');
            row.className = 'hover:bg-slate-50';
            
            const statusClass = student.status === 'active' ? 'status-active' :
                              student.status === 'inactive' ? 'status-inactive' :
                              student.status === 'pending' ? 'status-pending' : 'status-graduated';
            
            const attendanceClass = student.attendance >= 95 ? 'text-emerald-600' :
                                  student.attendance >= 90 ? 'text-amber-600' : 'text-red-600';
            
            const gradeClass = student.gpa >= 3.5 ? 'grade-a' :
                             student.gpa >= 3.0 ? 'grade-b' :
                             student.gpa >= 2.5 ? 'grade-c' :
                             student.gpa >= 2.0 ? 'grade-d' : 'grade-f';
            
            const gradeLetter = student.gpa >= 3.5 ? 'A' :
                              student.gpa >= 3.0 ? 'B' :
                              student.gpa >= 2.5 ? 'C' :
                              student.gpa >= 2.0 ? 'D' : 'F';
            
            const initials = `${student.firstName.charAt(0)}${student.lastName.charAt(0)}`;
            
            row.innerHTML = `
                <td>
                    <input type="checkbox" class="student-checkbox rounded border-slate-300" data-id="${student.id}" onchange="toggleStudentSelection(${student.id})">
                </td>
                <td>
                    <div class="flex items-center gap-3">
                        <div class="avatar avatar-md bg-gradient-to-br ${student.avatarColor}">
                            ${initials}
                        </div>
                        <div>
                            <p class="font-bold text-slate-900">${student.firstName} ${student.lastName}</p>
                            <p class="text-xs text-slate-500">${student.studentId}</p>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="flex items-center gap-2">
                        <span class="font-bold text-slate-900">Grade ${student.grade}</span>
                        <span class="text-sm text-slate-500">• Section ${student.section}</span>
                    </div>
                    <p class="text-xs text-slate-500 mt-1">Age: ${calculateAge(student.dateOfBirth)}</p>
                </td>
                <td>
                    <p class="font-medium text-slate-900">${student.parentName}</p>
                    <p class="text-xs text-slate-500">${student.parentRelationship}</p>
                </td>
                <td>
                    <p class="text-sm text-slate-900">${student.parentPhone}</p>
                    <p class="text-xs text-slate-500">${student.parentEmail}</p>
                </td>
                <td>
                    <span class="${statusClass}">${student.status.charAt(0).toUpperCase() + student.status.slice(1)}</span>
                </td>
                <td>
                    <div class="flex items-center gap-2">
                        <span class="font-bold ${attendanceClass}">${student.attendance}%</span>
                        <div class="progress-bar w-20">
                            <div class="progress-fill progress-success" style="width: ${student.attendance}%"></div>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="flex items-center gap-2">
                        <span class="grade-badge ${gradeClass}">${gradeLetter}</span>
                        <span class="font-bold text-slate-900">${student.gpa.toFixed(2)}</span>
                    </div>
                </td>
                <td>
                    <div class="flex items-center gap-2">
                        <button onclick="viewStudent(${student.id})" class="p-2 text-indigo-600 hover:bg-indigo-50 rounded-lg">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button onclick="editStudent(${student.id})" class="p-2 text-slate-600 hover:bg-slate-100 rounded-lg">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button onclick="deleteStudent(${student.id})" class="p-2 text-red-600 hover:bg-red-50 rounded-lg">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            `;
            
            return row;
        }

        // Calculate age from date of birth
        function calculateAge(dateOfBirth) {
            const today = new Date();
            const birthDate = new Date(dateOfBirth);
            let age = today.getFullYear() - birthDate.getFullYear();
            const monthDiff = today.getMonth() - birthDate.getMonth();
            
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                age--;
            }
            
            return age;
        }

        // Filter students data
        function filterStudentsData() {
            let filtered = [...studentsData];
            
            // Apply tab filter
            if (currentTab !== 'all') {
                filtered = filtered.filter(student => {
                    if (currentTab === 'active') return student.status === 'active';
                    if (currentTab === 'new') return student.enrollmentDate.includes('2024-09');
                    if (currentTab === 'graduating') return student.grade === '12';
                    if (currentTab === 'inactive') return student.status === 'inactive';
                    return true;
                });
            }
            
            // Apply search filter
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            if (searchTerm) {
                filtered = filtered.filter(student => 
                    student.firstName.toLowerCase().includes(searchTerm) || 
                    student.lastName.toLowerCase().includes(searchTerm) ||
                    student.studentId.toLowerCase().includes(searchTerm) ||
                    student.grade.toLowerCase().includes(searchTerm) ||
                    student.parentName.toLowerCase().includes(searchTerm)
                );
            }
            
            // Apply advanced filters
            const gradeFilter = document.getElementById('filterGrade')?.value;
            const sectionFilter = document.getElementById('filterSection')?.value;
            const genderFilter = document.getElementById('filterGender')?.value;
            const statusFilter = document.getElementById('filterStatus')?.value;
            
            if (gradeFilter) filtered = filtered.filter(s => s.grade === gradeFilter);
            if (sectionFilter) filtered = filtered.filter(s => s.section === sectionFilter);
            if (genderFilter) filtered = filtered.filter(s => s.gender === genderFilter);
            if (statusFilter) filtered = filtered.filter(s => s.status === statusFilter);
            
            return filtered;
        }

        // Filter students on search
        function filterStudents() {
            currentPage = 1;
            loadStudentsTable();
        }

        // Apply filters
        function applyFilters() {
            currentPage = 1;
            loadStudentsTable();
        }

        // Clear filters
        function clearFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('filterGrade').value = '';
            document.getElementById('filterSection').value = '';
            document.getElementById('filterGender').value = '';
            document.getElementById('filterStatus').value = '';
            
            // Reset chip filters
            document.querySelectorAll('.filter-chip').forEach(chip => {
                chip.classList.remove('active');
            });
            document.querySelector('.filter-chip[data-filter="all"]').classList.add('active');
            
            currentPage = 1;
            loadStudentsTable();
            
            Toast.info('All filters cleared');
        }

        // Toggle filter chip
        function toggleFilter(filterType) {
            // Update active chip
            document.querySelectorAll('.filter-chip').forEach(chip => {
                chip.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Apply filter
            currentPage = 1;
            loadStudentsTable();
            
            Toast.info(`Filtered by ${filterType.replace('-', ' ')}`);
        }

        // Toggle advanced filters
        function toggleAdvancedFilters() {
            const filters = document.getElementById('advancedFilters');
            filters.classList.toggle('hidden');
        }

        // Initialize charts
        function initializeCharts() {
            // Grade Distribution Chart
            const gradeCtx = document.getElementById('gradeDistributionChart').getContext('2d');
            gradeChart = new Chart(gradeCtx, {
                type: 'bar',
                data: {
                    labels: ['K', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12'],
                    datasets: [{
                        label: 'Students',
                        data: [85, 92, 88, 96, 102, 98, 105, 112, 108, 115, 186, 192, 178],
                        backgroundColor: [
                            '#4f46e5', '#6366f1', '#818cf8', '#a5b4fc',
                            '#10b981', '#34d399', '#6ee7b7',
                            '#f59e0b', '#fbbf24', '#fcd34d',
                            '#ef4444', '#f87171', '#fca5a5'
                        ],
                        borderWidth: 0,
                        borderRadius: 6,
                        hoverBackgroundColor: '#3730a3'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `Students: ${context.parsed.y}`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 50
                            },
                            grid: {
                                drawBorder: false
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }

        // Update grade chart
        function updateGradeChart() {
            Toast.info('Updating grade distribution chart...');
        }

        // Mobile sidebar toggle
        function mobileSidebarToggle() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
        }

        // Tab switching
        function switchTab(tabName) {
            currentTab = tabName;
            
            // Update active tab
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Update title
            const titles = {
                'all': 'All Students',
                'active': 'Active Students',
                'new': 'New Admissions',
                'graduating': 'Graduating Students',
                'inactive': 'Inactive Students'
            };
            document.getElementById('studentsTitle').textContent = titles[tabName] || 'Students';
            
            // Reload students
            currentPage = 1;
            loadStudentsTable();
            
            Toast.info(`Showing ${tabName} students`);
        }

        // View student
        function viewStudent(id) {
            const student = studentsData.find(s => s.id === id);
            if (!student) return;
            
            // Populate view modal
            document.getElementById('studentName').textContent = `${student.firstName} ${student.lastName}`;
            document.getElementById('studentAvatar').textContent = `${student.firstName.charAt(0)}${student.lastName.charAt(0)}`;
            document.getElementById('studentInfo').textContent = `Grade ${student.grade} • Section ${student.section} • ID: ${student.studentId}`;
            
            // Calculate age
            const age = calculateAge(student.dateOfBirth);
            
            // Set personal information
            document.getElementById('studentDOB').textContent = formatDate(student.dateOfBirth);
            document.getElementById('studentGender').textContent = student.gender.charAt(0).toUpperCase() + student.gender.slice(1);
            document.getElementById('studentAge').textContent = `${age} years`;
            document.getElementById('studentEnrollment').textContent = formatDate(student.enrollmentDate);
            
            // Set contact information
            document.getElementById('studentEmail').textContent = student.email;
            document.getElementById('studentPhone').textContent = student.phone;
            document.getElementById('studentAddress').textContent = student.address;
            
            // Set parent information
            document.getElementById('studentParent').textContent = student.parentName;
            document.getElementById('studentParentRel').textContent = student.parentRelationship;
            document.getElementById('studentParentEmail').textContent = student.parentEmail;
            document.getElementById('studentParentPhone').textContent = student.parentPhone;
            
            // Set emergency contact
            document.getElementById('studentEmergencyContact').textContent = student.emergencyContact;
            document.getElementById('studentEmergencyPhone').textContent = student.emergencyPhone;
            
            // Set medical information
            document.getElementById('studentMedical').textContent = student.medicalConditions || 'No known medical conditions';
            document.getElementById('studentAllergies').textContent = student.allergies || 'No known allergies';
            
            openModal('viewStudentModal');
        }

        // Format date
        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', { 
                month: 'long', 
                day: 'numeric', 
                year: 'numeric' 
            });
        }

        // Switch student tab
        function switchStudentTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.student-tab-content').forEach(content => {
                content.classList.add('hidden');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('#viewStudentModal .tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(`student${tabName.charAt(0).toUpperCase() + tabName.slice(1)}Tab`).classList.remove('hidden');
            event.target.classList.add('active');
        }

        // Edit student
        function editStudent(id) {
            const student = studentsData.find(s => s.id === id);
            if (!student) return;
            
            Toast.info(`Editing ${student.firstName} ${student.lastName}...`);
            closeModal('viewStudentModal');
            setTimeout(() => {
                openModal('addStudentModal');
            }, 300);
        }

        // Delete student
        function deleteStudent(id) {
            const student = studentsData.find(s => s.id === id);
            if (!student) return;
            
            if (confirm(`Are you sure you want to delete ${student.firstName} ${student.lastName}? This action cannot be undone.`)) {
                Toast.success(`Student ${student.firstName} ${student.lastName} deleted successfully`);
                loadStudentsTable();
            }
        }

        // Save new student
        function saveNewStudent() {
            const form = document.getElementById('addStudentForm');
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            
            Toast.success('New student added successfully!');
            closeModal('addStudentModal');
            loadStudentsTable();
        }

        // Modal functions
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }

        // Toggle student selection
        function toggleStudentSelection(id) {
            if (selectedStudents.has(id)) {
                selectedStudents.delete(id);
            } else {
                selectedStudents.add(id);
            }
            updateBulkActions();
        }

        // Select all students
        function selectAllStudents() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.student-checkbox');
            const filteredData = filterStudentsData();
            
            if (selectAll.checked) {
                filteredData.forEach(student => {
                    selectedStudents.add(student.id);
                });
                checkboxes.forEach(cb => cb.checked = true);
            } else {
                selectedStudents.clear();
                checkboxes.forEach(cb => cb.checked = false);
            }
            
            updateBulkActions();
        }

        // Update bulk actions
        function updateBulkActions() {
            const count = selectedStudents.size;
            const button = document.getElementById('bulkActionsBtn');
            const countSpan = document.getElementById('selectedCount');
            
            if (count > 0) {
                button.disabled = false;
                countSpan.textContent = `${count} student${count !== 1 ? 's' : ''} selected`;
            } else {
                button.disabled = true;
                countSpan.textContent = '0 students selected';
            }
            
            // Update select all checkbox
            const filteredData = filterStudentsData();
            const selectAll = document.getElementById('selectAll');
            selectAll.checked = selectedStudents.size > 0 && selectedStudents.size === filteredData.length;
            selectAll.indeterminate = selectedStudents.size > 0 && selectedStudents.size < filteredData.length;
        }

        // Bulk actions
        function bulkActions() {
            if (selectedStudents.size === 0) {
                Toast.warning('Please select students first');
                return;
            }
            
            Toast.info(`Performing bulk actions on ${selectedStudents.size} students...`);
        }

        // Quick actions
        function takeAttendance() {
            Toast.info('Opening attendance module...');
        }

        function generateReportCards() {
            Toast.info('Generating report cards...');
        }

        function sendParentNotifications() {
            Toast.info('Opening parent notification center...');
        }

        function manageClassLists() {
            Toast.info('Opening class list manager...');
        }

        // Generate reports
        function generateReports() {
            Toast.success('Student report generated successfully!<br>Your download will begin shortly.');
        }

        // Bulk import
        function downloadTemplate() {
            Toast.success('Template downloaded successfully!');
        }

        function uploadFile() {
            Toast.info('Opening file upload dialog...');
        }

        function processImport() {
            Toast.success('Import processed successfully!<br>24 students imported.');
            closeModal('bulkImportModal');
            loadStudentsTable();
        }

        // Print student list
        function printStudentList() {
            Toast.info('Opening print preview...');
        }

        // View all admissions
        function viewAllAdmissions() {
            Toast.info('Loading all admissions...');
        }

        // Print student record
        function printStudentRecord() {
            Toast.info('Opening print preview for student record...');
        }

        // Send parent message
        function sendParentMessage() {
            Toast.info('Opening parent message composer...');
        }

        // Transfer student
        function transferStudent() {
            Toast.info('Opening student transfer form...');
        }

        // Pagination
        function previousPage() {
            if (currentPage > 1) {
                currentPage--;
                loadStudentsTable();
            }
        }

        function nextPage() {
            const filteredData = filterStudentsData();
            const totalPages = Math.ceil(filteredData.length / itemsPerPage);
            if (currentPage < totalPages) {
                currentPage++;
                loadStudentsTable();
            }
        }

        function changePageSize(size) {
            itemsPerPage = parseInt(size);
            currentPage = 1;
            loadStudentsTable();
        }

        // Update pagination info
        function updatePaginationInfo(totalItems) {
            const startIndex = (currentPage - 1) * itemsPerPage + 1;
            const endIndex = Math.min(startIndex + itemsPerPage - 1, totalItems);
            document.getElementById('studentsCount').textContent = 
                `Showing ${startIndex}-${endIndex} of ${totalItems} students`;
        }

        // Setup event listeners
        function setupEventListeners() {
            // Select all checkbox
            document.getElementById('selectAll').addEventListener('change', selectAllStudents);
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl/Cmd + N for new student
                if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                    e.preventDefault();
                    openModal('addStudentModal');
                }
                
                // Ctrl/Cmd + F for search
                if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                    e.preventDefault();
                    document.getElementById('searchInput').focus();
                }
                
                // Esc to close modals
                if (e.key === 'Escape') {
                    closeModal('addStudentModal');
                    closeModal('viewStudentModal');
                    closeModal('bulkImportModal');
                }
            });
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            initializePage();
            
            // Welcome toast
            setTimeout(() => {
                Toast.success('Students Directory loaded successfully!', 3000);
            }, 1000);
            
            // Check for new admissions
            setTimeout(() => {
                const newAdmissions = studentsData.filter(s => s.enrollmentDate.includes('2024-09')).length;
                if (newAdmissions > 0) {
                    Toast.info(`You have ${newAdmissions} new student admissions this month`);
                }
            }, 2000);
        });
    </script>
</body>
</html>