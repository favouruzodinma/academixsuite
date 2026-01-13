<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AcademiXSuite | Smart Automation Flow for Every User</title>
    <meta name="description" content="See how AcademiXSuite automates workflows and provides intuitive interfaces for school admins, teachers, students, and parents. One platform, personalized experiences.">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200;400;600;800&family=Space+Grotesk:wght@300;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #6366f1;
            --accent: #f43f5e;
            --surface: #ffffff;
            --bg: #f8fafc;
        }

        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background-color: var(--bg);
            color: #0f172a;
            overflow-x: hidden;
        }

        h1, h2, h3, .font-heading { font-family: 'Space Grotesk', sans-serif; }

        /* Navigation */
        .glass-nav {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(20px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 1rem;
            margin: 1rem auto;
            width: 95%;
            max-width: 1200px;
        }

        /* User Journey Visualization */
        .journey-track {
            position: relative;
            height: 4px;
            background: linear-gradient(90deg, var(--primary) 0%, var(--secondary) 50%, var(--accent) 100%);
            border-radius: 2px;
            margin: 3rem 0;
        }
        
        .journey-marker {
            position: absolute;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
            transform: translateY(-50%);
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
        }
        
        .journey-marker:hover {
            transform: translateY(-50%) scale(1.1);
            box-shadow: 0 15px 30px rgba(0,0,0,0.3);
        }

        /* User Persona Cards */
        .persona-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 2px solid transparent;
        }
        
        .persona-card:hover {
            transform: translateY(-10px);
            border-color: var(--primary);
            box-shadow: 0 20px 40px rgba(37, 99, 235, 0.15);
        }
        
        .persona-admin { background: linear-gradient(135deg, #dbeafe 0%, #e0e7ff 100%); }
        .persona-teacher { background: linear-gradient(135deg, #f0f9ff 0%, #f0fdf4 100%); }
        .persona-student { background: linear-gradient(135deg, #fef2f2 0%, #fefce8 100%); }
        .persona-parent { background: linear-gradient(135deg, #faf5ff 0%, #f0f9ff 100%); }

        /* Automation Flow Steps */
        .flow-step {
            position: relative;
            padding: 2rem;
            border-radius: 1.5rem;
            background: white;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        
        .flow-step:hover {
            transform: translateX(10px);
            box-shadow: 0 20px 40px -10px rgba(0,0,0,0.15);
        }
        
        .flow-step-number {
            position: absolute;
            top: -15px;
            left: -15px;
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.25rem;
            box-shadow: 0 5px 15px rgba(37, 99, 235, 0.3);
        }

        /* Dashboard Mockup Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            grid-template-rows: repeat(6, 1fr);
            gap: 1rem;
            height: 400px;
        }
        
        .grid-item {
            border-radius: 0.75rem;
            background: white;
            border: 1px solid #e2e8f0;
            padding: 1rem;
            transition: all 0.3s ease;
        }
        
        .grid-item:hover {
            transform: scale(1.02);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }

        /* Interactive Demo Area */
        .demo-area {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 2px dashed #cbd5e1;
            border-radius: 1.5rem;
            padding: 2rem;
            transition: all 0.3s ease;
        }
        
        .demo-area:hover {
            border-color: var(--primary);
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
        }

        /* Comparison Table */
        .comparison-row {
            transition: all 0.3s ease;
            border-radius: 0.75rem;
        }
        
        .comparison-row:hover {
            background: #f8fafc;
            transform: translateX(5px);
        }

        /* Mobile Menu */
        .mobile-menu {
            display: none;
        }

        @media (max-width: 768px) {
            .mobile-menu {
                display: block;
            }
            
            .nav-links {
                display: none;
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: rgba(255, 255, 255, 0.95);
                backdrop-filter: blur(20px);
                padding: 1.5rem;
                border-radius: 1rem;
                box-shadow: 0 20px 40px rgba(0,0,0,0.1);
                z-index: 1000;
            }
            
            .nav-links.active {
                display: flex;
                flex-direction: column;
                gap: 1rem;
            }
            
            .dashboard-grid {
                grid-template-columns: repeat(6, 1fr);
                grid-template-rows: repeat(12, 1fr);
                height: 600px;
            }
        }

        /* Mobile adjustments */
        @media (max-width: 640px) {
            .glass-nav {
                padding: 0.75rem 1rem;
            }
            
            .flow-step {
                padding: 1.5rem;
            }
            
            h1 {
                font-size: 2.5rem !important;
            }
        }

        /* Animation Classes */
        .animate-pulse-glow {
            animation: pulse-glow 2s infinite;
        }
        
        @keyframes pulse-glow {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        .animate-float {
            animation: float 6s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }
    </style>
</head>
<body>

    <!-- Navigation -->
    <nav class="glass-nav sticky top-2 md:top-4 flex items-center justify-between px-4 md:px-8 py-3 md:py-4 z-[1000]">
        <div class="flex items-center space-x-2">
            <div class="w-8 h-8 md:w-10 md:h-10 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-lg md:rounded-xl flex items-center justify-center text-white shadow-lg">
                <i class="fas fa-microchip text-sm md:text-base"></i>
            </div>
            <span class="text-lg md:text-xl font-bold tracking-tight">AcademiX<span class="text-blue-600">Suite</span></span>
        </div>
        
        <button class="mobile-menu lg:hidden w-10 h-10 flex items-center justify-center text-slate-700">
            <i class="fas fa-bars text-xl"></i>
        </button>
        
        <div class="nav-links lg:flex space-x-4 lg:space-x-10 font-semibold text-slate-600 uppercase text-xs tracking-widest">
            <a href="/" class="hover:text-blue-600 transition py-2">Home</a>
            <a href="#personas" class="hover:text-blue-600 transition py-2">For Everyone</a>
            <a href="#automation" class="hover:text-blue-600 transition py-2">Automation Flow</a>
            <a href="#dashboards" class="hover:text-blue-600 transition py-2">Dashboards</a>
            <a href="#demo" class="hover:text-blue-600 transition py-2">Interactive Demo</a>
        </div>

        <div class="hidden lg:flex items-center space-x-4">
            <a href="/" class="text-sm font-bold text-slate-700 hover:text-blue-600">Back to Home</a>
            <button class="bg-slate-900 text-white px-6 py-2.5 rounded-full text-sm font-bold hover:bg-blue-600 transition shadow-xl">
                Get Started
            </button>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="pt-8 md:pt-16 pb-12 md:pb-24 px-4 md:px-6 bg-gradient-to-b from-white to-blue-50">
        <div class="container mx-auto text-center">
            <div data-aos="fade-down" class="mb-6">
                <span class="bg-rose-100 text-rose-600 px-4 py-2 rounded-full text-xs font-black uppercase tracking-widest border border-rose-200">
                    Smart Automation for Everyone
                </span>
            </div>
            <h1 class="text-3xl sm:text-4xl md:text-5xl lg:text-6xl font-black text-slate-900 leading-[1.1] md:leading-[1.05] mb-6 md:mb-8" data-aos="fade-up">
                One Platform.<br>
                <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-600 via-indigo-600 to-rose-500">Four Perfect Experiences.</span>
            </h1>
            <p class="text-lg md:text-2xl text-slate-600 max-w-4xl mx-auto mb-8 md:mb-12" data-aos="fade-up" data-aos-delay="100">
                See how AcademiXSuite intelligently adapts to provide the perfect interface for school administrators, teachers, students, and parents - all while automating workflows behind the scenes.
            </p>
            
            <div class="flex flex-wrap justify-center gap-4 md:gap-6" data-aos="fade-up" data-aos-delay="200">
                <button class="bg-blue-600 text-white px-6 md:px-10 py-3 md:py-4 rounded-xl md:rounded-2xl font-bold hover:bg-blue-700 transition shadow-lg">
                    <i class="fas fa-play-circle mr-2"></i> Watch Experience Tour
                </button>
                <button class="bg-white text-slate-900 px-6 md:px-10 py-3 md:py-4 rounded-xl md:rounded-2xl font-bold border-2 border-slate-200 hover:bg-slate-50 transition">
                    <i class="fas fa-hand-pointer mr-2"></i> Try Interactive Demo
                </button>
            </div>
        </div>
    </section>

    <!-- User Journey Visualization -->
    <section class="py-12 md:py-24 px-4 md:px-6 bg-white">
        <div class="container mx-auto">
            <h2 class="text-3xl md:text-4xl font-black text-center mb-12 md:mb-20" data-aos="fade-up">
                The <span class="text-blue-600">Complete Educational Journey</span>
            </h2>
            
            <div class="relative max-w-6xl mx-auto">
                <!-- Journey Track -->
                <div class="journey-track">
                    <div class="journey-marker" style="left: 0%; background: linear-gradient(135deg, var(--primary), #3b82f6);" data-persona="admin">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <div class="journey-marker" style="left: 33%; background: linear-gradient(135deg, #8b5cf6, var(--secondary));" data-persona="teacher">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <div class="journey-marker" style="left: 66%; background: linear-gradient(135deg, #f59e0b, #f97316);" data-persona="student">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="journey-marker" style="left: 100%; background: linear-gradient(135deg, var(--accent), #ec4899);" data-persona="parent">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                
                <!-- Journey Descriptions -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 md:gap-8 mt-16">
                    <div class="text-center" data-aos="fade-up">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-700 flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-user-tie text-white text-2xl"></i>
                        </div>
                        <h3 class="text-xl font-bold mb-2">School Administrators</h3>
                        <p class="text-slate-600">Complete control, automation, and insights for efficient school management</p>
                    </div>
                    
                    <div class="text-center" data-aos="fade-up" data-aos-delay="100">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-chalkboard-teacher text-white text-2xl"></i>
                        </div>
                        <h3 class="text-xl font-bold mb-2">Teachers</h3>
                        <p class="text-slate-600">Streamlined teaching tools, grading, and student progress tracking</p>
                    </div>
                    
                    <div class="text-center" data-aos="fade-up" data-aos-delay="200">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-amber-500 to-amber-700 flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-user-graduate text-white text-2xl"></i>
                        </div>
                        <h3 class="text-xl font-bold mb-2">Students</h3>
                        <p class="text-slate-600">Personal learning dashboard, assignments, and academic resources</p>
                    </div>
                    
                    <div class="text-center" data-aos="fade-up" data-aos-delay="300">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-rose-500 to-rose-700 flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-users text-white text-2xl"></i>
                        </div>
                        <h3 class="text-xl font-bold mb-2">Parents</h3>
                        <p class="text-slate-600">Real-time updates, fee management, and child progress monitoring</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- User Personas Section -->
    <section id="personas" class="py-16 md:py-32 bg-slate-50 px-4 md:px-6">
        <div class="container mx-auto">
            <div class="text-center max-w-3xl mx-auto mb-16 md:mb-24" data-aos="fade-up">
                <h2 class="text-blue-600 font-black uppercase tracking-[0.3em] text-xs mb-4">Personalized Experiences</h2>
                <h3 class="text-3xl md:text-4xl lg:text-5xl font-black mb-6 md:mb-8">Tailored Interfaces for<br><span class="text-slate-300">Every User Role</span></h3>
                <p class="text-slate-600 text-lg md:text-xl">Each user gets exactly what they need - no clutter, no confusion.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 md:gap-8">
                <!-- Admin Persona -->
                <div class="persona-card persona-admin p-6 md:p-8 rounded-2xl md:rounded-[2.5rem]" data-aos="fade-up">
                    <div class="flex items-center mb-6">
                        <div class="w-14 h-14 rounded-xl bg-gradient-to-br from-blue-500 to-blue-700 flex items-center justify-center mr-4">
                            <i class="fas fa-user-tie text-white text-2xl"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold">Administrator</h3>
                            <p class="text-blue-600 text-sm font-semibold">School Management</p>
                        </div>
                    </div>
                    <p class="text-slate-600 mb-6">Complete oversight with powerful analytics and automation tools for efficient school operations.</p>
                    <div class="space-y-3">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-blue-600 mr-3"></i>
                            <span class="text-slate-700 font-medium">Automated Reporting</span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-blue-600 mr-3"></i>
                            <span class="text-slate-700 font-medium">Financial Dashboard</span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-blue-600 mr-3"></i>
                            <span class="text-slate-700 font-medium">Staff Management</span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-blue-600 mr-3"></i>
                            <span class="text-slate-700 font-medium">Compliance Tracking</span>
                        </div>
                    </div>
                </div>

                <!-- Teacher Persona -->
                <div class="persona-card persona-teacher p-6 md:p-8 rounded-2xl md:rounded-[2.5rem]" data-aos="fade-up" data-aos-delay="100">
                    <div class="flex items-center mb-6">
                        <div class="w-14 h-14 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center mr-4">
                            <i class="fas fa-chalkboard-teacher text-white text-2xl"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold">Teacher</h3>
                            <p class="text-indigo-600 text-sm font-semibold">Teaching & Grading</p>
                        </div>
                    </div>
                    <p class="text-slate-600 mb-6">Streamlined tools for lesson planning, grading, and student progress monitoring.</p>
                    <div class="space-y-3">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-indigo-600 mr-3"></i>
                            <span class="text-slate-700 font-medium">Grade Automation</span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-indigo-600 mr-3"></i>
                            <span class="text-slate-700 font-medium">Attendance Tracking</span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-indigo-600 mr-3"></i>
                            <span class="text-slate-700 font-medium">Resource Sharing</span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-indigo-600 mr-3"></i>
                            <span class="text-slate-700 font-medium">Parent Communication</span>
                        </div>
                    </div>
                </div>

                <!-- Student Persona -->
                <div class="persona-card persona-student p-6 md:p-8 rounded-2xl md:rounded-[2.5rem]" data-aos="fade-up" data-aos-delay="200">
                    <div class="flex items-center mb-6">
                        <div class="w-14 h-14 rounded-xl bg-gradient-to-br from-amber-500 to-amber-700 flex items-center justify-center mr-4">
                            <i class="fas fa-user-graduate text-white text-2xl"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold">Student</h3>
                            <p class="text-amber-600 text-sm font-semibold">Learning & Progress</p>
                        </div>
                    </div>
                    <p class="text-slate-600 mb-6">Personalized learning dashboard with assignments, resources, and progress tracking.</p>
                    <div class="space-y-3">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-amber-600 mr-3"></i>
                            <span class="text-slate-700 font-medium">Assignment Portal</span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-amber-600 mr-3"></i>
                            <span class="text-slate-700 font-medium">Grade Tracking</span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-amber-600 mr-3"></i>
                            <span class="text-slate-700 font-medium">Resource Access</span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-amber-600 mr-3"></i>
                            <span class="text-slate-700 font-medium">Timetable View</span>
                        </div>
                    </div>
                </div>

                <!-- Parent Persona -->
                <div class="persona-card persona-parent p-6 md:p-8 rounded-2xl md:rounded-[2.5rem]" data-aos="fade-up" data-aos-delay="300">
                    <div class="flex items-center mb-6">
                        <div class="w-14 h-14 rounded-xl bg-gradient-to-br from-rose-500 to-rose-700 flex items-center justify-center mr-4">
                            <i class="fas fa-users text-white text-2xl"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold">Parent</h3>
                            <p class="text-rose-600 text-sm font-semibold">Monitoring & Payment</p>
                        </div>
                    </div>
                    <p class="text-slate-600 mb-6">Real-time updates, fee management, and comprehensive child progress monitoring.</p>
                    <div class="space-y-3">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-rose-600 mr-3"></i>
                            <span class="text-slate-700 font-medium">Progress Reports</span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-rose-600 mr-3"></i>
                            <span class="text-slate-700 font-medium">Fee Payments</span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-rose-600 mr-3"></i>
                            <span class="text-slate-700 font-medium">Communication Hub</span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-rose-600 mr-3"></i>
                            <span class="text-slate-700 font-medium">Event Calendar</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Automation Flow Section -->
    <section id="automation" class="py-16 md:py-32 bg-white px-4 md:px-6">
        <div class="container mx-auto">
            <div class="text-center max-w-3xl mx-auto mb-16 md:mb-24" data-aos="fade-up">
                <h2 class="text-rose-500 font-black uppercase tracking-[0.3em] text-xs mb-4">Smart Automation Flow</h2>
                <h3 class="text-3xl md:text-4xl lg:text-5xl font-black mb-6 md:mb-8">How Tasks Automatically Flow<br><span class="text-slate-300">Between All Users</span></h3>
                <p class="text-slate-600 text-lg md:text-xl">Watch how actions from one user automatically trigger relevant updates for others.</p>
            </div>

            <div class="space-y-8 md:space-y-12 max-w-4xl mx-auto">
                <!-- Step 1 -->
                <div class="flow-step" data-aos="fade-right">
                    <div class="flow-step-number">1</div>
                    <div class="flex flex-col md:flex-row items-start md:items-center gap-6">
                        <div class="flex-shrink-0">
                            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-700 flex items-center justify-center">
                                <i class="fas fa-user-tie text-white text-2xl"></i>
                            </div>
                        </div>
                        <div class="flex-grow">
                            <h3 class="text-xl font-bold mb-2">Admin Schedules Exam</h3>
                            <p class="text-slate-600 mb-3">School administrator creates an exam schedule with dates, subjects, and classrooms.</p>
                            <div class="flex items-center text-blue-600 font-medium">
                                <i class="fas fa-bolt mr-2"></i>
                                <span>Automation Trigger: Exam schedule published</span>
                            </div>
                        </div>
                        <div class="flex-shrink-0">
                            <div class="animate-pulse-glow">
                                <i class="fas fa-arrow-right text-2xl text-blue-500"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 2 -->
                <div class="flow-step" data-aos="fade-left" data-aos-delay="100">
                    <div class="flow-step-number">2</div>
                    <div class="flex flex-col md:flex-row items-start md:items-center gap-6">
                        <div class="flex-shrink-0">
                            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center">
                                <i class="fas fa-chalkboard-teacher text-white text-2xl"></i>
                            </div>
                        </div>
                        <div class="flex-grow">
                            <h3 class="text-xl font-bold mb-2">Teacher Uploads Study Materials</h3>
                            <p class="text-slate-600 mb-3">Teachers automatically notified and can upload study materials, past papers, and revision guides.</p>
                            <div class="flex items-center text-indigo-600 font-medium">
                                <i class="fas fa-bell mr-2"></i>
                                <span>Automatic Notification: Teachers receive exam details</span>
                            </div>
                        </div>
                        <div class="flex-shrink-0">
                            <div class="animate-pulse-glow">
                                <i class="fas fa-arrow-right text-2xl text-indigo-500"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 3 -->
                <div class="flow-step" data-aos="fade-right" data-aos-delay="200">
                    <div class="flow-step-number">3</div>
                    <div class="flex flex-col md:flex-row items-start md:items-center gap-6">
                        <div class="flex-shrink-0">
                            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-amber-500 to-amber-700 flex items-center justify-center">
                                <i class="fas fa-user-graduate text-white text-2xl"></i>
                            </div>
                        </div>
                        <div class="flex-grow">
                            <h3 class="text-xl font-bold mb-2">Students Access Resources</h3>
                            <p class="text-slate-600 mb-3">Students automatically see exam timetable and study materials in their personalized dashboard.</p>
                            <div class="flex items-center text-amber-600 font-medium">
                                <i class="fas fa-sync-alt mr-2"></i>
                                <span>Auto-Sync: Resources appear in student portal</span>
                            </div>
                        </div>
                        <div class="flex-shrink-0">
                            <div class="animate-pulse-glow">
                                <i class="fas fa-arrow-right text-2xl text-amber-500"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 4 -->
                <div class="flow-step" data-aos="fade-left" data-aos-delay="300">
                    <div class="flow-step-number">4</div>
                    <div class="flex flex-col md:flex-row items-start md:items-center gap-6">
                        <div class="flex-shrink-0">
                            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-rose-500 to-rose-700 flex items-center justify-center">
                                <i class="fas fa-users text-white text-2xl"></i>
                            </div>
                        </div>
                        <div class="flex-grow">
                            <h3 class="text-xl font-bold mb-2">Parents Receive Updates</h3>
                            <p class="text-slate-600 mb-3">Parents automatically get notifications about upcoming exams and can track study progress.</p>
                            <div class="flex items-center text-rose-600 font-medium">
                                <i class="fas fa-envelope mr-2"></i>
                                <span>Automated Alerts: Parents notified via email/SMS</span>
                            </div>
                        </div>
                        <div class="flex-shrink-0">
                            <div class="animate-pulse-glow">
                                <i class="fas fa-check-circle text-2xl text-emerald-500"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Dashboard Comparison -->
    <section id="dashboards" class="py-16 md:py-32 bg-slate-900 text-white px-4 md:px-6">
        <div class="container mx-auto">
            <div class="text-center max-w-3xl mx-auto mb-16 md:mb-24" data-aos="fade-up">
                <h2 class="text-blue-400 font-black uppercase tracking-[0.3em] text-xs mb-4">Intelligent Dashboards</h2>
                <h3 class="text-3xl md:text-4xl lg:text-5xl font-black mb-6 md:mb-8">Different Views,<br><span class="text-white/40">Same Platform</span></h3>
                <p class="text-slate-400 text-lg md:text-xl">Each user gets a dashboard optimized for their specific needs and responsibilities.</p>
            </div>

            <!-- Dashboard Grid Visualization -->
            <div class="mb-16" data-aos="zoom-in">
                <div class="dashboard-grid mb-8">
                    <!-- Admin Dashboard Area -->
                    <div class="grid-item col-span-3 row-span-2 bg-gradient-to-br from-blue-500/20 to-blue-600/20 border-blue-500/30">
                        <div class="flex items-center mb-3">
                            <div class="w-8 h-8 rounded-lg bg-blue-500 flex items-center justify-center mr-3">
                                <i class="fas fa-chart-line text-white text-sm"></i>
                            </div>
                            <span class="font-bold">Analytics</span>
                        </div>
                        <div class="text-xs text-blue-200">
                            <div class="h-2 bg-blue-400/30 rounded mb-2" style="width: 80%"></div>
                            <div class="h-2 bg-blue-400/30 rounded mb-2" style="width: 60%"></div>
                            <div class="h-2 bg-blue-400/30 rounded" style="width: 90%"></div>
                        </div>
                    </div>
                    
                    <!-- Teacher Dashboard Area -->
                    <div class="grid-item col-span-3 row-span-2 bg-gradient-to-br from-indigo-500/20 to-indigo-600/20 border-indigo-500/30">
                        <div class="flex items-center mb-3">
                            <div class="w-8 h-8 rounded-lg bg-indigo-500 flex items-center justify-center mr-3">
                                <i class="fas fa-graduation-cap text-white text-sm"></i>
                            </div>
                            <span class="font-bold">Classes</span>
                        </div>
                        <div class="text-xs text-indigo-200">
                            <div class="flex justify-between mb-1">
                                <span>Class 9A</span>
                                <span>85%</span>
                            </div>
                            <div class="h-2 bg-indigo-400/30 rounded mb-2" style="width: 85%"></div>
                        </div>
                    </div>
                    
                    <!-- Student Dashboard Area -->
                    <div class="grid-item col-span-3 row-span-2 bg-gradient-to-br from-amber-500/20 to-amber-600/20 border-amber-500/30">
                        <div class="flex items-center mb-3">
                            <div class="w-8 h-8 rounded-lg bg-amber-500 flex items-center justify-center mr-3">
                                <i class="fas fa-book text-white text-sm"></i>
                            </div>
                            <span class="font-bold">Assignments</span>
                        </div>
                        <div class="text-xs text-amber-200">
                            <div class="flex justify-between mb-1">
                                <span>Math</span>
                                <span>Due Tomorrow</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Parent Dashboard Area -->
                    <div class="grid-item col-span-3 row-span-2 bg-gradient-to-br from-rose-500/20 to-rose-600/20 border-rose-500/30">
                        <div class="flex items-center mb-3">
                            <div class="w-8 h-8 rounded-lg bg-rose-500 flex items-center justify-center mr-3">
                                <i class="fas fa-wallet text-white text-sm"></i>
                            </div>
                            <span class="font-bold">Fees</span>
                        </div>
                        <div class="text-xs text-rose-200">
                            <div class="flex justify-between mb-1">
                                <span>Balance</span>
                                <span>₦45,000</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Additional Dashboard Elements -->
                    <div class="grid-item col-span-4 row-span-2 bg-gradient-to-br from-slate-800 to-slate-900">
                        <div class="text-center h-full flex flex-col items-center justify-center">
                            <i class="fas fa-sync-alt text-3xl text-blue-400 mb-3"></i>
                            <span class="font-bold">Real-time Sync</span>
                            <p class="text-xs text-slate-400 mt-2">All dashboards update simultaneously</p>
                        </div>
                    </div>
                    
                    <div class="grid-item col-span-4 row-span-2 bg-gradient-to-br from-slate-800 to-slate-900">
                        <div class="text-center h-full flex flex-col items-center justify-center">
                            <i class="fas fa-shield-alt text-3xl text-emerald-400 mb-3"></i>
                            <span class="font-bold">Secure Access</span>
                            <p class="text-xs text-slate-400 mt-2">Role-based permissions</p>
                        </div>
                    </div>
                    
                    <div class="grid-item col-span-4 row-span-2 bg-gradient-to-br from-slate-800 to-slate-900">
                        <div class="text-center h-full flex flex-col items-center justify-center">
                            <i class="fas fa-mobile-alt text-3xl text-purple-400 mb-3"></i>
                            <span class="font-bold">Mobile Ready</span>
                            <p class="text-xs text-slate-400 mt-2">Access from any device</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dashboard Comparison Table -->
            <div class="overflow-x-auto" data-aos="fade-up">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-slate-700">
                            <th class="pb-4 font-bold text-slate-300">Feature</th>
                            <th class="pb-4 font-bold text-blue-400 text-center">Admin</th>
                            <th class="pb-4 font-bold text-indigo-400 text-center">Teacher</th>
                            <th class="pb-4 font-bold text-amber-400 text-center">Student</th>
                            <th class="pb-4 font-bold text-rose-400 text-center">Parent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="comparison-row border-b border-slate-800">
                            <td class="py-4 font-medium">Analytics & Reports</td>
                            <td class="py-4 text-center"><i class="fas fa-check-circle text-blue-400"></i></td>
                            <td class="py-4 text-center"><i class="fas fa-check-circle text-indigo-400"></i></td>
                            <td class="py-4 text-center"><i class="fas fa-chart-simple text-slate-500"></i></td>
                            <td class="py-4 text-center"><i class="fas fa-chart-simple text-slate-500"></i></td>
                        </tr>
                        <tr class="comparison-row border-b border-slate-800">
                            <td class="py-4 font-medium">Grade Management</td>
                            <td class="py-4 text-center"><i class="fas fa-eye text-slate-500"></i></td>
                            <td class="py-4 text-center"><i class="fas fa-check-circle text-indigo-400"></i></td>
                            <td class="py-4 text-center"><i class="fas fa-check-circle text-amber-400"></i></td>
                            <td class="py-4 text-center"><i class="fas fa-check-circle text-rose-400"></i></td>
                        </tr>
                        <tr class="comparison-row border-b border-slate-800">
                            <td class="py-4 font-medium">Fee Management</td>
                            <td class="py-4 text-center"><i class="fas fa-check-circle text-blue-400"></i></td>
                            <td class="py-4 text-center"><i class="fas fa-times text-slate-500"></i></td>
                            <td class="py-4 text-center"><i class="fas fa-times text-slate-500"></i></td>
                            <td class="py-4 text-center"><i class="fas fa-check-circle text-rose-400"></i></td>
                        </tr>
                        <tr class="comparison-row border-b border-slate-800">
                            <td class="py-4 font-medium">Assignment Submission</td>
                            <td class="py-4 text-center"><i class="fas fa-eye text-slate-500"></i></td>
                            <td class="py-4 text-center"><i class="fas fa-check-circle text-indigo-400"></i></td>
                            <td class="py-4 text-center"><i class="fas fa-check-circle text-amber-400"></i></td>
                            <td class="py-4 text-center"><i class="fas fa-eye text-slate-500"></i></td>
                        </tr>
                        <tr class="comparison-row">
                            <td class="py-4 font-medium">Communication Tools</td>
                            <td class="py-4 text-center"><i class="fas fa-check-circle text-blue-400"></i></td>
                            <td class="py-4 text-center"><i class="fas fa-check-circle text-indigo-400"></i></td>
                            <td class="py-4 text-center"><i class="fas fa-check-circle text-amber-400"></i></td>
                            <td class="py-4 text-center"><i class="fas fa-check-circle text-rose-400"></i></td>
                        </tr>
                    </tbody>
                </table>
                <div class="mt-6 text-center text-slate-400 text-sm">
                    <i class="fas fa-check-circle text-emerald-400 mr-2"></i> Full Access
                    <span class="mx-4">|</span>
                    <i class="fas fa-eye text-slate-500 mr-2"></i> View Only
                    <span class="mx-4">|</span>
                    <i class="fas fa-times text-slate-500 mr-2"></i> Not Available
                </div>
            </div>
        </div>
    </section>

    <!-- Interactive Demo -->
    <section id="demo" class="py-16 md:py-32 bg-gradient-to-b from-white to-blue-50 px-4 md:px-6">
        <div class="container mx-auto">
            <div class="text-center max-w-3xl mx-auto mb-16 md:mb-24" data-aos="fade-up">
                <h2 class="text-rose-500 font-black uppercase tracking-[0.3em] text-xs mb-4">Try It Yourself</h2>
                <h3 class="text-3xl md:text-4xl lg:text-5xl font-black mb-6 md:mb-8">Experience the Automation<br><span class="text-slate-300">First Hand</span></h3>
                <p class="text-slate-600 text-lg md:text-xl">See how a single action triggers automated updates across all user types.</p>
            </div>

            <div class="max-w-4xl mx-auto" data-aos="zoom-in">
                <div class="demo-area text-center">
                    <div class="mb-8">
                        <h4 class="text-2xl font-bold mb-4">Automation Demo: Assignment Workflow</h4>
                        <p class="text-slate-600 mb-6">Select a user role to see what happens when they take an action:</p>
                    </div>
                    
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                        <button class="demo-role py-4 rounded-xl bg-blue-100 text-blue-700 font-bold border-2 border-blue-300 hover:bg-blue-200 transition" data-role="admin">
                            <i class="fas fa-user-tie text-xl mb-2 block"></i>
                            Admin
                        </button>
                        <button class="demo-role py-4 rounded-xl bg-indigo-100 text-indigo-700 font-bold border-2 border-indigo-300 hover:bg-indigo-200 transition" data-role="teacher">
                            <i class="fas fa-chalkboard-teacher text-xl mb-2 block"></i>
                            Teacher
                        </button>
                        <button class="demo-role py-4 rounded-xl bg-amber-100 text-amber-700 font-bold border-2 border-amber-300 hover:bg-amber-200 transition" data-role="student">
                            <i class="fas fa-user-graduate text-xl mb-2 block"></i>
                            Student
                        </button>
                        <button class="demo-role py-4 rounded-xl bg-rose-100 text-rose-700 font-bold border-2 border-rose-300 hover:bg-rose-200 transition" data-role="parent">
                            <i class="fas fa-users text-xl mb-2 block"></i>
                            Parent
                        </button>
                    </div>
                    
                    <!-- Action Selector -->
                    <div class="mb-8">
                        <select id="demo-action" class="w-full max-w-md mx-auto bg-white border-2 border-slate-300 rounded-xl p-4 font-bold outline-none focus:border-blue-500">
                            <option value="">Select an action to simulate</option>
                            <option value="teacher_assign">Teacher: Assign Homework</option>
                            <option value="student_submit">Student: Submit Assignment</option>
                            <option value="teacher_grade">Teacher: Grade Assignment</option>
                            <option value="parent_view">Parent: View Grades</option>
                            <option value="admin_report">Admin: Generate Report</option>
                        </select>
                    </div>
                    
                    <!-- Automation Results -->
                    <div id="demo-results" class="text-left bg-white rounded-xl p-6 shadow-lg hidden">
                        <h5 class="text-xl font-bold mb-4 text-blue-600">Automation Results:</h5>
                        <div class="space-y-4" id="automation-steps">
                            <!-- Results will appear here -->
                        </div>
                    </div>
                    
                    <!-- Reset Button -->
                    <button id="reset-demo" class="mt-8 bg-slate-200 text-slate-700 px-6 py-3 rounded-xl font-bold hover:bg-slate-300 transition">
                        <i class="fas fa-redo mr-2"></i> Reset Demo
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- Benefits Section -->
    <section class="py-16 md:py-32 bg-white px-4 md:px-6">
        <div class="container mx-auto">
            <div class="text-center max-w-3xl mx-auto mb-16 md:mb-24" data-aos="fade-up">
                <h2 class="text-blue-600 font-black uppercase tracking-[0.3em] text-xs mb-4">Key Benefits</h2>
                <h3 class="text-3xl md:text-4xl lg:text-5xl font-black mb-6 md:mb-8">Why Schools Love<br><span class="text-slate-300">Our Unified Platform</span></h3>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 md:gap-12">
                <div class="text-center" data-aos="fade-up">
                    <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-700 flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-bolt text-white text-3xl"></i>
                    </div>
                    <h4 class="text-xl font-bold mb-4">80% Time Saved</h4>
                    <p class="text-slate-600">Automated workflows reduce manual data entry and communication tasks dramatically.</p>
                </div>
                
                <div class="text-center" data-aos="fade-up" data-aos-delay="100">
                    <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-user-check text-white text-3xl"></i>
                    </div>
                    <h4 class="text-xl font-bold mb-4">Improved Satisfaction</h4>
                    <p class="text-slate-600">Parents, teachers, and students all report higher satisfaction with streamlined processes.</p>
                </div>
                
                <div class="text-center" data-aos="fade-up" data-aos-delay="200">
                    <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-purple-500 to-purple-700 flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-chart-line text-white text-3xl"></i>
                    </div>
                    <h4 class="text-xl font-bold mb-4">Better Insights</h4>
                    <p class="text-slate-600">Unified data provides comprehensive insights for better decision-making.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Final CTA -->
    <section class="py-16 md:py-32 bg-gradient-to-br from-slate-900 to-blue-900 text-white px-4 md:px-6">
        <div class="container mx-auto text-center">
            <h2 class="text-3xl md:text-5xl lg:text-6xl font-black mb-8 md:mb-12" data-aos="fade-up">
                Ready to Experience<br class="hidden md:block">
                <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-rose-400">Smart Automation?</span>
            </h2>
            <p class="text-xl md:text-2xl text-blue-200 max-w-3xl mx-auto mb-10 md:mb-16" data-aos="fade-up" data-aos-delay="100">
                Join thousands of schools that have transformed their workflows with AcademiXSuite's intelligent automation.
            </p>
            
            <div class="flex flex-col sm:flex-row justify-center gap-6 md:gap-8" data-aos="fade-up" data-aos-delay="200">
                <button class="bg-white text-blue-900 px-8 md:px-12 py-4 md:py-6 rounded-xl md:rounded-2xl font-bold text-lg md:text-xl hover:scale-105 transition shadow-2xl">
                    <i class="fas fa-calendar-alt mr-2"></i> Schedule Live Demo
                </button>
                <button class="bg-gradient-to-r from-blue-600 to-rose-600 text-white px-8 md:px-12 py-4 md:py-6 rounded-xl md:rounded-2xl font-bold text-lg md:text-xl hover:scale-105 transition shadow-2xl">
                    <i class="fas fa-rocket mr-2"></i> Start Free Trial
                </button>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-white pt-12 md:pt-24 pb-8 md:pb-12 border-t border-slate-100 px-4 md:px-6">
        <div class="container mx-auto grid grid-cols-1 md:grid-cols-4 gap-8 md:gap-16 mb-10 md:mb-20">
            <div class="col-span-1 md:col-span-2">
                <div class="flex items-center space-x-2 mb-6 md:mb-8">
                    <div class="w-8 h-8 md:w-10 md:h-10 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-lg md:rounded-xl flex items-center justify-center text-white shadow-lg">
                        <i class="fas fa-microchip text-sm md:text-base"></i>
                    </div>
                    <span class="text-lg md:text-2xl font-bold tracking-tight text-slate-900">AcademiX<span class="text-blue-600">Suite</span></span>
                </div>
                <p class="text-slate-500 text-sm md:text-lg max-w-sm">Smart automation and personalized experiences for every user in your educational ecosystem.</p>
            </div>
            <div>
                <h5 class="font-black text-slate-900 uppercase text-xs tracking-widest mb-6 md:mb-8">User Experiences</h5>
                <ul class="space-y-3 md:space-y-4 text-slate-500 font-medium text-sm">
                    <li><a href="#personas" class="hover:text-blue-600 transition">For Administrators</a></li>
                    <li><a href="#personas" class="hover:text-blue-600 transition">For Teachers</a></li>
                    <li><a href="#personas" class="hover:text-blue-600 transition">For Students</a></li>
                    <li><a href="#personas" class="hover:text-blue-600 transition">For Parents</a></li>
                </ul>
            </div>
            <div>
                <h5 class="font-black text-slate-900 uppercase text-xs tracking-widest mb-6 md:mb-8">Learn More</h5>
                <div class="space-y-4">
                    <a href="#" class="block text-blue-600 font-medium text-sm hover:text-blue-700">
                        <i class="fas fa-file-video mr-2"></i> Video Tutorials
                    </a>
                    <a href="#" class="block text-blue-600 font-medium text-sm hover:text-blue-700">
                        <i class="fas fa-book-open mr-2"></i> Documentation
                    </a>
                    <a href="#" class="block text-blue-600 font-medium text-sm hover:text-blue-700">
                        <i class="fas fa-headset mr-2"></i> Support Center
                    </a>
                </div>
            </div>
        </div>
        <div class="text-center pt-8 md:pt-12 border-t border-slate-100">
            <p class="text-slate-400 font-medium text-sm">© 2026 AcademiXSuite. One Platform, Perfect Experiences for Everyone.</p>
        </div>
    </footer>

    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init({
            duration: 1000,
            once: true,
            offset: 100
        });

        // Mobile Menu Toggle
        const mobileMenuBtn = document.querySelector('.mobile-menu');
        const navLinks = document.querySelector('.nav-links');
        
        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', () => {
                navLinks.classList.toggle('active');
                const icon = mobileMenuBtn.querySelector('i');
                if (navLinks.classList.contains('active')) {
                    icon.classList.remove('fa-bars');
                    icon.classList.add('fa-times');
                } else {
                    icon.classList.remove('fa-times');
                    icon.classList.add('fa-bars');
                }
            });
        }

        // Close mobile menu when clicking outside
        document.addEventListener('click', (e) => {
            if (!mobileMenuBtn.contains(e.target) && !navLinks.contains(e.target)) {
                navLinks.classList.remove('active');
                const icon = mobileMenuBtn.querySelector('i');
                icon.classList.remove('fa-times');
                icon.classList.add('fa-bars');
            }
        });

        // Journey Marker Interactions
        const journeyMarkers = document.querySelectorAll('.journey-marker');
        journeyMarkers.forEach(marker => {
            marker.addEventListener('mouseenter', () => {
                const persona = marker.getAttribute('data-persona');
                highlightPersona(persona);
            });
            
            marker.addEventListener('mouseleave', () => {
                removeHighlights();
            });
        });
        
        function highlightPersona(persona) {
            // Remove all highlights first
            removeHighlights();
            
            // Add highlight class to matching persona cards
            document.querySelectorAll('.persona-card').forEach(card => {
                if (card.classList.contains(`persona-${persona}`)) {
                    card.style.transform = 'translateY(-15px)';
                    card.style.boxShadow = '0 25px 50px rgba(0,0,0,0.15)';
                }
            });
        }
        
        function removeHighlights() {
            document.querySelectorAll('.persona-card').forEach(card => {
                card.style.transform = '';
                card.style.boxShadow = '';
            });
        }

        // Interactive Demo Functionality
        const demoRoles = document.querySelectorAll('.demo-role');
        const demoAction = document.getElementById('demo-action');
        const demoResults = document.getElementById('demo-results');
        const automationSteps = document.getElementById('automation-steps');
        const resetButton = document.getElementById('reset-demo');
        
        // Demo scenarios data
        const demoScenarios = {
            teacher_assign: {
                title: "Teacher Assigns Homework",
                steps: [
                    {
                        icon: "fas fa-chalkboard-teacher text-indigo-500",
                        text: "Teacher creates and publishes homework assignment",
                        color: "border-l-indigo-500"
                    },
                    {
                        icon: "fas fa-bell text-blue-500",
                        text: "Students automatically receive notification",
                        color: "border-l-blue-500"
                    },
                    {
                        icon: "fas fa-user-graduate text-amber-500",
                        text: "Assignment appears in student dashboards",
                        color: "border-l-amber-500"
                    },
                    {
                        icon: "fas fa-envelope text-rose-500",
                        text: "Parents get automated email about new assignment",
                        color: "border-l-rose-500"
                    },
                    {
                        icon: "fas fa-database text-emerald-500",
                        text: "Admin dashboard updates with new assignment data",
                        color: "border-l-emerald-500"
                    }
                ]
            },
            student_submit: {
                title: "Student Submits Assignment",
                steps: [
                    {
                        icon: "fas fa-user-graduate text-amber-500",
                        text: "Student uploads completed assignment",
                        color: "border-l-amber-500"
                    },
                    {
                        icon: "fas fa-bell text-indigo-500",
                        text: "Teacher automatically notified of submission",
                        color: "border-l-indigo-500"
                    },
                    {
                        icon: "fas fa-check-circle text-emerald-500",
                        text: "System marks assignment as submitted",
                        color: "border-l-emerald-500"
                    },
                    {
                        icon: "fas fa-eye text-rose-500",
                        text: "Parent can see submission status",
                        color: "border-l-rose-500"
                    }
                ]
            },
            teacher_grade: {
                title: "Teacher Grades Assignment",
                steps: [
                    {
                        icon: "fas fa-chalkboard-teacher text-indigo-500",
                        text: "Teacher grades assignment and adds feedback",
                        color: "border-l-indigo-500"
                    },
                    {
                        icon: "fas fa-user-graduate text-amber-500",
                        text: "Student immediately sees grade and feedback",
                        color: "border-l-amber-500"
                    },
                    {
                        icon: "fas fa-envelope text-rose-500",
                        text: "Parent receives grade notification",
                        color: "border-l-rose-500"
                    },
                    {
                        icon: "fas fa-chart-line text-blue-500",
                        text: "Admin analytics update with new grade data",
                        color: "border-l-blue-500"
                    }
                ]
            },
            parent_view: {
                title: "Parent Views Progress",
                steps: [
                    {
                        icon: "fas fa-users text-rose-500",
                        text: "Parent logs into parent portal",
                        color: "border-l-rose-500"
                    },
                    {
                        icon: "fas fa-chart-line text-amber-500",
                        text: "Real-time academic progress displayed",
                        color: "border-l-amber-500"
                    },
                    {
                        icon: "fas fa-wallet text-blue-500",
                        text: "Fee status and payment options shown",
                        color: "border-l-blue-500"
                    },
                    {
                        icon: "fas fa-calendar-alt text-indigo-500",
                        text: "Upcoming events and assignments visible",
                        color: "border-l-indigo-500"
                    }
                ]
            },
            admin_report: {
                title: "Admin Generates Report",
                steps: [
                    {
                        icon: "fas fa-user-tie text-blue-500",
                        text: "Admin requests academic performance report",
                        color: "border-l-blue-500"
                    },
                    {
                        icon: "fas fa-robot text-purple-500",
                        text: "AI analyzes data from all sources",
                        color: "border-l-purple-500"
                    },
                    {
                        icon: "fas fa-file-pdf text-emerald-500",
                        text: "Automated report generated in seconds",
                        color: "border-l-emerald-500"
                    },
                    {
                        icon: "fas fa-share-alt text-indigo-500",
                        text: "Report automatically shared with stakeholders",
                        color: "border-l-indigo-500"
                    }
                ]
            }
        };
        
        // Demo role selection
        demoRoles.forEach(role => {
            role.addEventListener('click', () => {
                // Remove active class from all roles
                demoRoles.forEach(r => {
                    r.classList.remove('ring-2', 'ring-offset-2');
                    r.classList.remove('ring-blue-500', 'ring-indigo-500', 'ring-amber-500', 'ring-rose-500');
                });
                
                // Add active class to clicked role
                const roleType = role.getAttribute('data-role');
                role.classList.add('ring-2', 'ring-offset-2');
                
                // Set appropriate ring color
                switch(roleType) {
                    case 'admin':
                        role.classList.add('ring-blue-500');
                        break;
                    case 'teacher':
                        role.classList.add('ring-indigo-500');
                        break;
                    case 'student':
                        role.classList.add('ring-amber-500');
                        break;
                    case 'parent':
                        role.classList.add('ring-rose-500');
                        break;
                }
                
                // Update action dropdown based on role
                updateActionsForRole(roleType);
            });
        });
        
        function updateActionsForRole(role) {
            const actionSelect = document.getElementById('demo-action');
            actionSelect.innerHTML = '<option value="">Select an action to simulate</option>';
            
            switch(role) {
                case 'admin':
                    actionSelect.innerHTML += `
                        <option value="admin_report">Generate Performance Report</option>
                        <option value="teacher_assign">Schedule School Event</option>
                    `;
                    break;
                case 'teacher':
                    actionSelect.innerHTML += `
                        <option value="teacher_assign">Assign Homework</option>
                        <option value="teacher_grade">Grade Assignment</option>
                    `;
                    break;
                case 'student':
                    actionSelect.innerHTML += `
                        <option value="student_submit">Submit Assignment</option>
                        <option value="parent_view">View Grades</option>
                    `;
                    break;
                case 'parent':
                    actionSelect.innerHTML += `
                        <option value="parent_view">View Child's Progress</option>
                        <option value="admin_report">Make Fee Payment</option>
                    `;
                    break;
            }
        }
        
        // Action selection
        demoAction.addEventListener('change', () => {
            const selectedAction = demoAction.value;
            
            if (selectedAction && demoScenarios[selectedAction]) {
                showDemoResults(selectedAction);
            } else {
                hideDemoResults();
            }
        });
        
        function showDemoResults(action) {
            const scenario = demoScenarios[action];
            
            // Update title
            const resultsTitle = demoResults.querySelector('h5');
            resultsTitle.textContent = `Automation Results: ${scenario.title}`;
            
            // Clear previous steps
            automationSteps.innerHTML = '';
            
            // Add new steps
            scenario.steps.forEach((step, index) => {
                const stepElement = document.createElement('div');
                stepElement.className = `flex items-start p-4 rounded-lg bg-slate-50 ${step.color} border-l-4`;
                stepElement.innerHTML = `
                    <div class="flex-shrink-0 mr-4">
                        <i class="${step.icon} text-xl"></i>
                    </div>
                    <div>
                        <p class="font-medium">${step.text}</p>
                        <div class="flex items-center mt-2 text-sm text-slate-500">
                            <i class="fas fa-clock mr-2"></i>
                            <span>Automated • ${index === 0 ? 'Immediate' : 'Real-time'}</span>
                        </div>
                    </div>
                `;
                automationSteps.appendChild(stepElement);
            });
            
            // Show results
            demoResults.classList.remove('hidden');
            demoResults.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        
        function hideDemoResults() {
            demoResults.classList.add('hidden');
        }
        
        // Reset demo
        resetButton.addEventListener('click', () => {
            // Reset role selection
            demoRoles.forEach(role => {
                role.classList.remove('ring-2', 'ring-offset-2');
                role.classList.remove('ring-blue-500', 'ring-indigo-500', 'ring-amber-500', 'ring-rose-500');
            });
            
            // Reset action dropdown
            demoAction.value = '';
            demoAction.innerHTML = '<option value="">Select an action to simulate</option>';
            
            // Hide results
            hideDemoResults();
        });

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const targetId = this.getAttribute('href');
                if (targetId === '#') return;
                
                const targetElement = document.querySelector(targetId);
                if (targetElement) {
                    targetElement.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                    
                    // Close mobile menu if open
                    navLinks.classList.remove('active');
                    const icon = mobileMenuBtn.querySelector('i');
                    icon.classList.remove('fa-times');
                    icon.classList.add('fa-bars');
                }
            });
        });

        // Initialize animations
        document.addEventListener('DOMContentLoaded', () => {
            // Add floating animation to journey markers
            journeyMarkers.forEach((marker, index) => {
                marker.style.animationDelay = `${index * 0.5}s`;
                marker.classList.add('animate-float');
            });
        });
    </script>
</body>
</html>