<?php
// public/index.php - Public homepage
require_once __DIR__ . '/../config/constants.php';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AcademiX Pro | The World's Most Advanced School Ecosystem</title>
    
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

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--primary); }

        /* Outside the Box: Floating Glassmorphism Navigation */
        .glass-nav {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(20px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 1rem;
            margin: 1rem auto;
            width: 95%;
            max-width: 1200px;
        }

        /* Immersive Hero Header */
        .hero-mesh {
            background-color: #ffffff;
            background-image: 
                radial-gradient(at 0% 0%, rgba(37, 99, 235, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 0%, rgba(99, 102, 241, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(244, 63, 94, 0.1) 0px, transparent 50%);
        }

        /* The "Digital Layer" Effect */
        .layer-card {
            transition: all 0.5s cubic-bezier(0.23, 1, 0.32, 1);
            position: relative;
            z-index: 1;
        }
        .layer-card:hover {
            transform: translateY(-15px) scale(1.02);
            z-index: 10;
        }
        .layer-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            opacity: 0;
            transition: opacity 0.5s ease;
            z-index: -1;
            border-radius: inherit;
        }
        .layer-card:hover::before { opacity: 0.05; }

        /* Animated Blobs */
        .blob {
            position: absolute;
            width: 300px;
            height: 300px;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.2), rgba(99, 102, 241, 0.2));
            filter: blur(80px);
            border-radius: 50%;
            z-index: -1;
            animation: move 20s infinite alternate;
        }

        @media (min-width: 768px) {
            .blob {
                width: 500px;
                height: 500px;
            }
        }

        @keyframes move {
            from { transform: translate(-10%, -10%); }
            to { transform: translate(20%, 20%); }
        }

        /* Module Hub Grid */
        .module-icon {
            font-size: 2.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Dashboard Mockup Styling */
        .dashboard-mockup {
            box-shadow: 0 20px 60px -20px rgba(0,0,0,0.15), 0 15px 40px -15px rgba(0,0,0,0.2);
            border: 1px solid rgba(255,255,255,0.4);
        }

        /* Advanced Filter styling */
        .filter-pill {
            transition: all 0.3s ease;
            cursor: pointer;
            border: 1px solid #e2e8f0;
            display: inline-block;
        }
        .filter-pill:hover, .filter-pill.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* FAQ Accordion Detail */
        .faq-trigger:checked ~ .faq-content {
            max-height: 500px;
            opacity: 1;
            padding: 1.5rem;
        }

        /* NEW: Platform Capabilities Section */
        .capability-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        @media (min-width: 768px) {
            .capability-grid {
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 2rem;
            }
        }

        /* NEW: Statistics Counter Animation */
        .stat-number {
            font-feature-settings: "tnum";
            font-variant-numeric: tabular-nums;
        }

        /* NEW: CTA Gradient Background */
        .cta-gradient {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
        }

        /* NEW: Feature Tabs */
        .feature-tab {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            white-space: nowrap;
        }
        .feature-tab.active {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.3);
        }

        /* NEW: School Comparison Table */
        .comparison-table {
            border-collapse: separate;
            border-spacing: 0;
        }
        .comparison-table th {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        /* NEW: Animation for floating elements */
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }
        .animate-float {
            animation: float 6s ease-in-out infinite;
        }

        /* NEW: Trust Badge Styling */
        .trust-badge {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Mobile Menu Toggle */
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
        }

        /* Mobile padding and spacing fixes */
        @media (max-width: 640px) {
            .glass-nav {
                padding: 0.75rem 1rem;
            }
            
            .hero-mesh {
                padding-left: 1rem;
                padding-right: 1rem;
            }
            
            section {
                padding-left: 1rem;
                padding-right: 1rem;
            }
            
            .dashboard-mockup {
                border-radius: 1.5rem;
            }
            
            .layer-card {
                border-radius: 1.5rem !important;
                padding: 1.5rem !important;
            }
            
            h1 {
                font-size: 2.5rem !important;
                line-height: 1.2 !important;
            }
            
            h2, h3 {
                font-size: 2rem !important;
                line-height: 1.3 !important;
            }
        }
    </style>
</head>
<body>

    <div class="blob" style="top: 10%; left: -5%;"></div>
    <div class="blob" style="bottom: 10%; right: -5%; background: rgba(244, 63, 94, 0.1);"></div>

    <nav class="glass-nav sticky top-2 md:top-4 flex items-center justify-between px-4 md:px-8 py-3 md:py-4 z-[1000]">
        <div class="flex items-center space-x-2">
            <div class="w-8 h-8 md:w-10 md:h-10 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-lg md:rounded-xl flex items-center justify-center text-white shadow-lg">
                <i class="fas fa-microchip text-sm md:text-base"></i>
            </div>
            <span class="text-lg md:text-xl font-bold tracking-tight">AcademiX<span class="text-blue-600">Suite</span></span>
        </div>
        
        <!-- Mobile Menu Button -->
        <button class="mobile-menu lg:hidden w-10 h-10 flex items-center justify-center text-slate-700">
            <i class="fas fa-bars text-xl"></i>
        </button>
        
        <div class="nav-links lg:flex space-x-4 lg:space-x-10 font-semibold text-slate-600 uppercase text-xs tracking-widest">
            <a href="#platform" class="hover:text-blue-600 transition py-2">Platform</a>
            <a href="#capabilities" class="hover:text-blue-600 transition py-2">Capabilities</a>
            <a href="#discovery" class="hover:text-blue-600 transition py-2">Discovery</a>
            <a href="#comparison" class="hover:text-blue-600 transition py-2">Comparison</a>
            <a href="#testimonials" class="hover:text-blue-600 transition py-2">Community</a>
            <a href="#pricing" class="hover:text-blue-600 transition py-2">Pricing</a>
        </div>

        <div class="hidden lg:flex items-center space-x-4">
            <a href="#" class="text-sm font-bold text-slate-700">Log In</a>
            <button class="bg-slate-900 text-white px-6 py-2.5 rounded-full text-sm font-bold hover:bg-blue-600 transition shadow-xl">
                Get Demo
            </button>
        </div>
    </nav>

    <header class="hero-mesh relative px-4 md:px-6 pt-12 md:pt-16 pb-20 md:pb-32 overflow-hidden">
        <div class="container mx-auto text-center relative z-10">
            <div data-aos="fade-down" class="mb-6">
                <span class="bg-rose-100 text-rose-600 px-3 md:px-4 py-1 md:py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest border border-rose-200">
                    Trusted by 2,400+ Institutions
                </span>
            </div>
            <h1 class="text-3xl sm:text-4xl md:text-5xl lg:text-8xl font-black text-slate-900 leading-[1.1] md:leading-[1.05] mb-6 md:mb-8" data-aos="fade-up">
                Redefining the <br class="hidden md:block">
                <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-600 via-indigo-600 to-rose-500">Educational DNA.</span>
            </h1>
            <p class="text-base md:text-xl lg:text-2xl text-slate-500 max-w-3xl mx-auto mb-8 md:mb-12 font-light leading-relaxed" data-aos="fade-up" data-aos-delay="100">
                Stop managing schools. Start building legacies. A unified, AI-driven operating system for modern Nigerian education.
            </p>

            <!-- NEW: Trust Badges -->
            <div class="flex flex-wrap justify-center items-center gap-4 md:gap-8 mb-8 md:mb-12" data-aos="fade-up" data-aos-delay="150">
                <div class="flex items-center">
                    <div class="flex -space-x-1 md:-space-x-2 mr-2 md:mr-4">
                        <div class="w-6 h-6 md:w-8 md:h-8 rounded-full bg-gradient-to-r from-blue-500 to-blue-700 border-2 border-white"></div>
                        <div class="w-6 h-6 md:w-8 md:h-8 rounded-full bg-gradient-to-r from-green-500 to-green-700 border-2 border-white"></div>
                        <div class="w-6 h-6 md:w-8 md:h-8 rounded-full bg-gradient-to-r from-purple-500 to-purple-700 border-2 border-white"></div>
                    </div>
                    <div>
                        <p class="font-bold text-slate-900 text-sm md:text-base">2,400+ Schools</p>
                        <p class="text-xs text-slate-500">Trust Our Platform</p>
                    </div>
                </div>
                
                <div class="flex items-center">
                    <div class="flex text-yellow-400 mr-2 md:mr-3 text-sm md:text-base">
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star-half-alt"></i>
                    </div>
                    <div>
                        <p class="font-bold text-slate-900 text-sm md:text-base">4.8/5 Rating</p>
                        <p class="text-xs text-slate-500">Based on 5,200+ reviews</p>
                    </div>
                </div>
                
                <div class="flex items-center">
                    <div class="w-6 h-6 md:w-8 md:h-8 rounded-full bg-green-100 flex items-center justify-center mr-2 md:mr-3">
                        <i class="fas fa-shield-alt text-green-600 text-sm md:text-base"></i>
                    </div>
                    <div>
                        <p class="font-bold text-slate-900 text-sm md:text-base">Bank-Level Security</p>
                        <p class="text-xs text-slate-500">SOC 2 Type II Certified</p>
                    </div>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row justify-center gap-4 md:gap-6" data-aos="fade-up" data-aos-delay="200">
                <button class="bg-blue-600 text-white px-6 md:px-10 py-3 md:py-5 rounded-xl md:rounded-2xl font-bold text-base md:text-xl shadow-[0_10px_25px_-5px_rgba(37,99,235,0.5)] hover:scale-105 transition">
                    Onboard Your School
                </button>
                <button class="bg-white text-slate-900 px-6 md:px-10 py-3 md:py-5 rounded-xl md:rounded-2xl font-bold text-base md:text-xl border border-slate-200 hover:bg-slate-50 transition">
                    <i class="fas fa-play-circle mr-2"></i> Watch Demo
                </button>
            </div>
        </div>

        <div class="container mx-auto mt-12 md:mt-24 relative px-0 md:px-4" data-aos="zoom-in" data-aos-delay="300">
            <div class="dashboard-mockup bg-white rounded-2xl md:rounded-[3rem] overflow-hidden p-2 md:p-4 relative z-20 mx-2 md:mx-0">
                <div class="bg-slate-950 rounded-2xl md:rounded-[2.5rem] overflow-hidden aspect-[16/9] relative">
                    <img src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=1200&q=80" class="w-full h-full object-cover opacity-60">
                    
                    <div class="absolute top-4 md:top-10 left-4 md:left-10 space-y-2 md:space-y-4">
                        <div class="bg-white/10 backdrop-blur-md p-4 md:p-6 rounded-2xl md:rounded-3xl border border-white/20 w-40 md:w-64 animate-float">
                            <p class="text-white/60 text-xs font-bold uppercase mb-1 tracking-widest">Total Revenue</p>
                            <p class="text-white text-xl md:text-3xl font-black">₦12.4M</p>
                            <div class="flex items-center text-emerald-400 text-xs font-bold mt-2">
                                <i class="fas fa-arrow-up mr-1"></i> +14% Growth
                            </div>
                        </div>
                        <div class="bg-white/10 backdrop-blur-md p-4 md:p-6 rounded-2xl md:rounded-3xl border border-white/20 w-40 md:w-64 animate-float" style="animation-delay: -3s">
                            <p class="text-white/60 text-xs font-bold uppercase mb-1 tracking-widest">Security Status</p>
                            <p class="text-white text-lg md:text-xl font-black"><i class="fas fa-shield-check mr-2"></i> All Safe</p>
                            <p class="text-white/40 text-[10px] mt-2 italic">Real-time pickup tracking active</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="absolute -top-6 md:-top-10 -right-4 md:-right-10 w-48 md:w-72 bg-white rounded-2xl md:rounded-3xl shadow-2xl p-4 md:p-6 z-30 hidden lg:block border border-slate-100 animate-float">
                <div class="flex items-center space-x-3 md:space-x-4 mb-3 md:mb-4">
                    <div class="w-8 h-8 md:w-12 md:h-12 bg-indigo-100 rounded-xl md:rounded-2xl flex items-center justify-center text-indigo-600">
                        <i class="fas fa-robot text-lg md:text-xl"></i>
                    </div>
                    <div>
                        <p class="font-bold text-slate-900 text-sm md:text-base">AI Assistant</p>
                        <p class="text-[10px] text-slate-400">Processing 1,200 Exams</p>
                    </div>
                </div>
                <div class="space-y-2">
                    <div class="h-2 w-full bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full bg-indigo-600" style="width: 85%"></div>
                    </div>
                    <p class="text-[10px] text-right text-slate-500 font-bold">85% Optimization</p>
                </div>
            </div>
        </div>
    </header>

    <!-- NEW: Platform Capabilities Section -->
    <section id="platform" class="py-16 md:py-32 bg-white px-4 md:px-6">
        <div class="container mx-auto">
            <div class="text-center max-w-3xl mx-auto mb-10 md:mb-20" data-aos="fade-up">
                <h2 class="text-rose-500 font-black uppercase tracking-[0.4em] text-xs mb-4">Complete Platform</h2>
                <h3 class="text-2xl md:text-4xl lg:text-6xl font-black mb-6 md:mb-8">What You Can Do<br><span class="text-slate-300">With AcademiX Suite</span></h3>
                <p class="text-slate-500 text-base md:text-lg">Transform every aspect of school administration with our comprehensive feature suite.</p>
            </div>

            <!-- Feature Tabs -->
            <div class="flex flex-wrap justify-center gap-3 md:gap-4 mb-8 md:mb-12 overflow-x-auto pb-4" data-aos="fade-up">
                <button class="feature-tab active px-4 md:px-6 py-2 md:py-3 rounded-full text-xs md:text-sm font-bold border border-slate-200">
                    Academic Management
                </button>
                <button class="feature-tab px-4 md:px-6 py-2 md:py-3 rounded-full text-xs md:text-sm font-bold border border-slate-200">
                    Financial Automation
                </button>
                <button class="feature-tab px-4 md:px-6 py-2 md:py-3 rounded-full text-xs md:text-sm font-bold border border-slate-200">
                    Security & Safety
                </button>
                <button class="feature-tab px-4 md:px-6 py-2 md:py-3 rounded-full text-xs md:text-sm font-bold border border-slate-200">
                    Communication Hub
                </button>
            </div>

            <!-- Feature Content -->
            <div class="capability-grid">
                <!-- Academic Management -->
                <div class="layer-card p-6 md:p-8 rounded-2xl md:rounded-[2.5rem] bg-slate-50" data-aos="fade-up">
                    <div class="w-12 h-12 md:w-14 md:h-14 rounded-xl md:rounded-2xl bg-gradient-to-br from-blue-500 to-blue-700 flex items-center justify-center mb-4 md:mb-6">
                        <i class="fas fa-graduation-cap text-white text-xl md:text-2xl"></i>
                    </div>
                    <h4 class="text-lg md:text-xl font-bold mb-3 md:mb-4">Student Lifecycle Management</h4>
                    <p class="text-slate-500 text-sm mb-4 md:mb-6">Track students from admission to alumni with complete academic history, attendance, behavior records, and health information.</p>
                    <div class="flex items-center text-blue-600 font-bold">
                        <span>Explore Module</span>
                        <i class="fas fa-arrow-right ml-2"></i>
                    </div>
                </div>

                <!-- Financial Automation -->
                <div class="layer-card p-6 md:p-8 rounded-2xl md:rounded-[2.5rem] bg-slate-50" data-aos="fade-up" data-aos-delay="100">
                    <div class="w-12 h-12 md:w-14 md:h-14 rounded-xl md:rounded-2xl bg-gradient-to-br from-green-500 to-green-700 flex items-center justify-center mb-4 md:mb-6">
                        <i class="fas fa-wallet text-white text-xl md:text-2xl"></i>
                    </div>
                    <h4 class="text-lg md:text-xl font-bold mb-3 md:mb-4">Smart Financial Operations</h4>
                    <p class="text-slate-500 text-sm mb-4 md:mb-6">Automated fee collection, expense tracking, payroll processing, budgeting, and comprehensive financial reporting with audit trails.</p>
                    <div class="flex items-center text-green-600 font-bold">
                        <span>Explore Module</span>
                        <i class="fas fa-arrow-right ml-2"></i>
                    </div>
                </div>

                <!-- Security & Safety -->
                <div class="layer-card p-6 md:p-8 rounded-2xl md:rounded-[2.5rem] bg-slate-50" data-aos="fade-up" data-aos-delay="200">
                    <div class="w-12 h-12 md:w-14 md:h-14 rounded-xl md:rounded-2xl bg-gradient-to-br from-purple-500 to-purple-700 flex items-center justify-center mb-4 md:mb-6">
                        <i class="fas fa-shield-alt text-white text-xl md:text-2xl"></i>
                    </div>
                    <h4 class="text-lg md:text-xl font-bold mb-3 md:mb-4">Enterprise Security Suite</h4>
                    <p class="text-slate-500 text-sm mb-4 md:mb-6">Bank-level encryption, biometric access control, real-time surveillance integration, and GDPR compliance for complete data protection.</p>
                    <div class="flex items-center text-purple-600 font-bold">
                        <span>Explore Module</span>
                        <i class="fas fa-arrow-right ml-2"></i>
                    </div>
                </div>

                <!-- Communication Hub -->
                <div class="layer-card p-6 md:p-8 rounded-2xl md:rounded-[2.5rem] bg-slate-50" data-aos="fade-up" data-aos-delay="300">
                    <div class="w-12 h-12 md:w-14 md:h-14 rounded-xl md:rounded-2xl bg-gradient-to-br from-amber-500 to-amber-700 flex items-center justify-center mb-4 md:mb-6">
                        <i class="fas fa-comments text-white text-xl md:text-2xl"></i>
                    </div>
                    <h4 class="text-lg md:text-xl font-bold mb-3 md:mb-4">Parent-Teacher Connect</h4>
                    <p class="text-slate-500 text-sm mb-4 md:mb-6">Real-time messaging, automated notifications, progress reports, event calendars, and dedicated parent portal with mobile app.</p>
                    <div class="flex items-center text-amber-600 font-bold">
                        <span>Explore Module</span>
                        <i class="fas fa-arrow-right ml-2"></i>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- NEW: Statistics Section -->
    <section id="capabilities" class="py-16 md:py-32 bg-slate-900 text-white px-4 md:px-6">
        <div class="container mx-auto">
            <div class="text-center max-w-3xl mx-auto mb-10 md:mb-20" data-aos="fade-up">
                <h2 class="text-blue-500 font-black uppercase tracking-[0.4em] text-xs mb-4">Impact Metrics</h2>
                <h3 class="text-2xl md:text-4xl lg:text-6xl font-black mb-6 md:mb-8">Proven Results<br><span class="text-white/40">Across All Institutions</span></h3>
                <p class="text-slate-400 text-base md:text-lg">See how AcademiX Pro delivers tangible benefits to educational institutions of all sizes.</p>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-6 md:gap-8">
                <div class="text-center" data-aos="fade-up">
                    <p class="text-3xl md:text-5xl font-black mb-2 stat-number" data-count="94">94</p>
                    <p class="text-blue-300 font-bold uppercase text-xs tracking-widest">% Admin Efficiency</p>
                    <p class="text-slate-400 text-xs md:text-sm mt-2">Reduced manual work</p>
                </div>
                <div class="text-center" data-aos="fade-up" data-aos-delay="100">
                    <p class="text-3xl md:text-5xl font-black mb-2 stat-number" data-count="78">78</p>
                    <p class="text-green-300 font-bold uppercase text-xs tracking-widest">% Faster Admissions</p>
                    <p class="text-slate-400 text-xs md:text-sm mt-2">Application processing</p>
                </div>
                <div class="text-center" data-aos="fade-up" data-aos-delay="200">
                    <p class="text-3xl md:text-5xl font-black mb-2 stat-number" data-count="99.9">99.9</p>
                    <p class="text-purple-300 font-bold uppercase text-xs tracking-widest">% Uptime</p>
                    <p class="text-slate-400 text-xs md:text-sm mt-2">Platform reliability</p>
                </div>
                <div class="text-center" data-aos="fade-up" data-aos-delay="300">
                    <p class="text-3xl md:text-5xl font-black mb-2 stat-number" data-count="65">65</p>
                    <p class="text-rose-300 font-bold uppercase text-xs tracking-widest">% Parent Engagement</p>
                    <p class="text-slate-400 text-xs md:text-sm mt-2">Increase with portal</p>
                </div>
            </div>

            <!-- Progress Bars -->
            <div class="mt-10 md:mt-20 space-y-6 md:space-y-8 max-w-3xl mx-auto" data-aos="fade-up">
                <div>
                    <div class="flex justify-between mb-2">
                        <span class="font-bold text-sm md:text-base">Financial Accuracy</span>
                        <span class="font-bold text-emerald-400">98%</span>
                    </div>
                    <div class="h-2 w-full bg-slate-800 rounded-full overflow-hidden">
                        <div class="h-full bg-emerald-500 rounded-full" style="width: 98%"></div>
                    </div>
                </div>
                <div>
                    <div class="flex justify-between mb-2">
                        <span class="font-bold text-sm md:text-base">Staff Productivity</span>
                        <span class="font-bold text-blue-400">76%</span>
                    </div>
                    <div class="h-2 w-full bg-slate-800 rounded-full overflow-hidden">
                        <div class="h-full bg-blue-500 rounded-full" style="width: 76%"></div>
                    </div>
                </div>
                <div>
                    <div class="flex justify-between mb-2">
                        <span class="font-bold text-sm md:text-base">Student Performance</span>
                        <span class="font-bold text-purple-400">22%</span>
                    </div>
                    <div class="h-2 w-full bg-slate-800 rounded-full overflow-hidden">
                        <div class="h-full bg-purple-500 rounded-full" style="width: 22%"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Existing Discovery Section (Enhanced) -->
    <section id="discovery" class="py-16 md:py-32 bg-slate-900 text-white px-4 md:px-6">
        <div class="container mx-auto">
            <div class="flex flex-col lg:flex-row items-center justify-between mb-10 md:mb-20">
                <div class="max-w-2xl" data-aos="fade-right">
                    <h2 class="text-blue-500 font-black uppercase tracking-[0.4em] text-xs mb-4">Discovery Engine</h2>
                    <h3 class="text-2xl md:text-4xl lg:text-6xl font-black leading-tight">Find the perfect <br> <span class="text-white/40">academic environment.</span></h3>
                </div>
                <p class="text-slate-400 text-base md:text-lg max-w-sm mt-6 md:mt-8 lg:mt-0" data-aos="fade-left">
                    Use our intelligent geolocation and budget filters to discover schools that match your legacy.
                </p>
            </div>

            <!-- Enhanced Filter System -->
            <div class="bg-white/5 rounded-2xl md:rounded-[3rem] p-4 md:p-8 border border-white/10" data-aos="zoom-in">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4 md:gap-6">
                    <div class="space-y-3">
                        <label class="text-[10px] font-black uppercase text-blue-500 tracking-widest">State / Region</label>
                        <select class="w-full bg-slate-800 border border-slate-700 rounded-xl md:rounded-2xl p-3 md:p-4 text-white font-bold appearance-none outline-none focus:border-blue-500 text-sm md:text-base">
                            <option value="">All States</option>
                            <option>Rivers State</option>
                            <option>Lagos State</option>
                            <option>Abuja (FCT)</option>
                            <option>Oyo State</option>
                            <option>Kano State</option>
                            <option>Enugu State</option>
                        </select>
                    </div>
                    <div class="space-y-3">
                        <label class="text-[10px] font-black uppercase text-blue-500 tracking-widest">Fee Range (₦)</label>
                        <select class="w-full bg-slate-800 border border-slate-700 rounded-xl md:rounded-2xl p-3 md:p-4 text-white font-bold appearance-none outline-none focus:border-blue-500 text-sm md:text-base">
                            <option value="">All Ranges</option>
                            <option>100k - 300k</option>
                            <option>300k - 800k</option>
                            <option>800k - 2M</option>
                            <option>Above 2M</option>
                        </select>
                    </div>
                    <div class="space-y-3">
                        <label class="text-[10px] font-black uppercase text-blue-500 tracking-widest">School Type</label>
                        <select class="w-full bg-slate-800 border border-slate-700 rounded-xl md:rounded-2xl p-3 md:p-4 text-white font-bold appearance-none outline-none focus:border-blue-500 text-sm md:text-base">
                            <option value="">All Types</option>
                            <option>Private</option>
                            <option>Public</option>
                            <option>International</option>
                            <option>Boarding</option>
                        </select>
                    </div>
                    <div class="space-y-3">
                        <label class="text-[10px] font-black uppercase text-blue-500 tracking-widest">Curriculum</label>
                        <div class="flex flex-wrap gap-2">
                            <span class="filter-pill px-3 md:px-4 py-1.5 md:py-2 rounded-full text-[10px] font-bold">British</span>
                            <span class="filter-pill px-3 md:px-4 py-1.5 md:py-2 rounded-full text-[10px] font-bold">American</span>
                            <span class="filter-pill active px-3 md:px-4 py-1.5 md:py-2 rounded-full text-[10px] font-bold">Nigerian</span>
                            <span class="filter-pill px-3 md:px-4 py-1.5 md:py-2 rounded-full text-[10px] font-bold">Montessori</span>
                        </div>
                    </div>
                    <button class="bg-blue-600 hover:bg-blue-700 text-white rounded-xl md:rounded-2xl p-3 md:p-4 font-bold h-auto md:h-[72px] mt-0 md:mt-7 transition-all flex items-center justify-center space-x-2 md:space-x-3 shadow-xl shadow-blue-900/40">
                        <i class="fas fa-search"></i>
                        <span class="text-sm md:text-base">Search Schools</span>
                    </button>
                </div>
            </div>
            
            <!-- School Results Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 md:gap-10 mt-10 md:mt-20">
                <div class="layer-card bg-slate-800/50 rounded-xl md:rounded-[2.5rem] overflow-hidden border border-slate-700" data-aos="fade-up">
                    <img src="https://images.unsplash.com/photo-1562774053-701939374585?auto=format&fit=crop&w=600&q=80" class="w-full h-48 md:h-56 object-cover">
                    <div class="p-4 md:p-8">
                        <div class="flex justify-between items-center mb-3 md:mb-4">
                            <span class="text-blue-500 text-[10px] font-black uppercase">Rivers State</span>
                            <div class="flex text-yellow-400 text-[10px]">
                                <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                            </div>
                        </div>
                        <h4 class="text-lg md:text-xl font-bold mb-2">Jarspok International</h4>
                        <p class="text-slate-500 text-xs md:text-sm mb-3 md:mb-4">Expert technical training with standard child-safety ERP integration.</p>
                        <div class="flex justify-between items-center mb-4 md:mb-6">
                            <span class="text-green-400 font-bold text-sm md:text-base">₦350,000/yr</span>
                            <span class="text-xs text-slate-400">1,200 Students</span>
                        </div>
                        <button class="w-full py-3 md:py-4 border border-slate-700 rounded-xl md:rounded-2xl text-xs font-black uppercase tracking-widest hover:bg-white hover:text-slate-900 transition">View Details</button>
                    </div>
                </div>
                <!-- Additional school cards... -->
            </div>
        </div>
    </section>

    <!-- NEW: Platform Comparison Section -->
    <section id="comparison" class="py-16 md:py-32 bg-white px-4 md:px-6">
        <div class="container mx-auto">
            <div class="text-center max-w-3xl mx-auto mb-10 md:mb-20" data-aos="fade-up">
                <h2 class="text-rose-500 font-black uppercase tracking-[0.4em] text-xs mb-4">Why Choose Us</h2>
                <h3 class="text-2xl md:text-4xl lg:text-6xl font-black mb-6 md:mb-8">The Complete Solution<br><span class="text-slate-300">vs. Basic Systems</span></h3>
                <p class="text-slate-500 text-base md:text-lg">See how AcademiX Pro outperforms traditional school management systems.</p>
            </div>

            <div class="bg-slate-50 rounded-xl md:rounded-[2.5rem] p-4 md:p-8 overflow-hidden" data-aos="zoom-in">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-8">
                    <div class="text-center">
                        <div class="w-12 h-12 md:w-16 md:h-16 rounded-xl md:rounded-2xl bg-gradient-to-br from-blue-500 to-blue-700 flex items-center justify-center mx-auto mb-3 md:mb-4">
                            <i class="fas fa-check text-white text-lg md:text-2xl"></i>
                        </div>
                        <h4 class="font-bold text-slate-900 text-sm md:text-base mb-2">All-in-One Platform</h4>
                        <p class="text-slate-600 text-xs md:text-sm">Single solution vs. multiple disconnected systems</p>
                    </div>
                    <div class="text-center">
                        <div class="w-12 h-12 md:w-16 md:h-16 rounded-xl md:rounded-2xl bg-gradient-to-br from-green-500 to-green-700 flex items-center justify-center mx-auto mb-3 md:mb-4">
                            <i class="fas fa-robot text-white text-lg md:text-2xl"></i>
                        </div>
                        <h4 class="font-bold text-slate-900 text-sm md:text-base mb-2">AI-Powered Insights</h4>
                        <p class="text-slate-600 text-xs md:text-sm">Predictive analytics vs. basic reporting</p>
                    </div>
                    <div class="text-center">
                        <div class="w-12 h-12 md:w-16 md:h-16 rounded-xl md:rounded-2xl bg-gradient-to-br from-purple-500 to-purple-700 flex items-center justify-center mx-auto mb-3 md:mb-4">
                            <i class="fas fa-shield-alt text-white text-lg md:text-2xl"></i>
                        </div>
                        <h4 class="font-bold text-slate-900 text-sm md:text-base mb-2">Bank-Level Security</h4>
                        <p class="text-slate-600 text-xs md:text-sm">Enterprise security vs. basic password protection</p>
                    </div>
                    <div class="text-center">
                        <div class="w-12 h-12 md:w-16 md:h-16 rounded-xl md:rounded-2xl bg-gradient-to-br from-amber-500 to-amber-700 flex items-center justify-center mx-auto mb-3 md:mb-4">
                            <i class="fas fa-headset text-white text-lg md:text-2xl"></i>
                        </div>
                        <h4 class="font-bold text-slate-900 text-sm md:text-base mb-2">24/7 Support</h4>
                        <p class="text-slate-600 text-xs md:text-sm">Dedicated support vs. email-only assistance</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- NEW: Pricing Section -->
    <section id="pricing" class="py-16 md:py-32 bg-slate-50 px-4 md:px-6">
        <div class="container mx-auto">
            <div class="text-center max-w-3xl mx-auto mb-10 md:mb-20" data-aos="fade-up">
                <h2 class="text-blue-500 font-black uppercase tracking-[0.4em] text-xs mb-4">Scalable Solutions</h2>
                <h3 class="text-2xl md:text-4xl lg:text-6xl font-black mb-6 md:mb-8">Transparent Pricing<br><span class="text-slate-300">For Every Institution</span></h3>
                <p class="text-slate-500 text-base md:text-lg">Choose the perfect plan for your school's needs and growth ambitions.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 md:gap-8 max-w-6xl mx-auto">
                <!-- Starter Plan -->
                <div class="layer-card bg-white p-6 md:p-8 rounded-xl md:rounded-[2.5rem] border border-slate-200" data-aos="fade-up" data-aos-delay="100">
                    <h4 class="font-black text-slate-400 uppercase text-xs mb-3 md:mb-4">Starter</h4>
                    <p class="text-3xl md:text-4xl font-black mb-4 md:mb-6">₦149,000<span class="text-sm md:text-base text-slate-400">/term</span></p>
                    <ul class="space-y-3 md:space-y-4 mb-6 md:mb-8 text-slate-600 font-medium text-sm md:text-base">
                        <li><i class="fas fa-check text-blue-500 mr-2"></i> Up to 200 Students</li>
                        <li><i class="fas fa-check text-blue-500 mr-2"></i> Basic Admissions</li>
                        <li><i class="fas fa-check text-blue-500 mr-2"></i> Attendance Tracking</li>
                        <li><i class="fas fa-check text-blue-500 mr-2"></i> Digital Report Cards</li>
                        <li><i class="fas fa-times text-slate-300 mr-2"></i> Financial Management</li>
                        <li><i class="fas fa-times text-slate-300 mr-2"></i> AI Analytics</li>
                    </ul>
                    <button class="w-full py-3 md:py-4 border-2 border-slate-200 rounded-xl md:rounded-2xl font-bold hover:bg-slate-50 transition text-sm md:text-base">
                        Choose Starter
                    </button>
                </div>

                <!-- Professional Plan -->
                <div class="layer-card bg-white p-6 md:p-8 rounded-xl md:rounded-[2.5rem] border-2 md:border-4 border-blue-600 shadow-lg md:shadow-2xl md:scale-105 z-10 relative" data-aos="fade-up">
                    <div class="absolute top-0 right-4 md:right-6 -translate-y-1/2">
                        <span class="bg-blue-600 text-white px-3 md:px-4 py-1 md:py-2 rounded-full text-xs font-bold">Most Popular</span>
                    </div>
                    <h4 class="font-black text-blue-600 uppercase text-xs mb-3 md:mb-4">Professional</h4>
                    <p class="text-3xl md:text-4xl font-black mb-4 md:mb-6">₦399,000<span class="text-sm md:text-base text-slate-400">/term</span></p>
                    <ul class="space-y-3 md:space-y-4 mb-6 md:mb-8 text-slate-600 font-medium text-sm md:text-base">
                        <li><i class="fas fa-check text-blue-500 mr-2"></i> Unlimited Students</li>
                        <li><i class="fas fa-check text-blue-500 mr-2"></i> Advanced Admissions</li>
                        <li><i class="fas fa-check text-blue-500 mr-2"></i> Financial Management</li>
                        <li><i class="fas fa-check text-blue-500 mr-2"></i> AI-Powered Analytics</li>
                        <li><i class="fas fa-check text-blue-500 mr-2"></i> Parent Mobile App</li>
                        <li><i class="fas fa-check text-blue-500 mr-2"></i> 24/7 Support</li>
                    </ul>
                    <button class="bg-blue-600 text-white w-full py-3 md:py-4 rounded-xl md:rounded-2xl font-bold hover:bg-blue-700 transition shadow-lg shadow-blue-200 text-sm md:text-base">
                        Start Free Trial
                    </button>
                </div>

                <!-- Enterprise Plan -->
                <div class="layer-card bg-white p-6 md:p-8 rounded-xl md:rounded-[2.5rem] border border-slate-200" data-aos="fade-up" data-aos-delay="200">
                    <h4 class="font-black text-slate-400 uppercase text-xs mb-3 md:mb-4">Enterprise</h4>
                    <p class="text-3xl md:text-4xl font-black mb-4 md:mb-6">Custom</p>
                    <ul class="space-y-3 md:space-y-4 mb-6 md:mb-8 text-slate-600 font-medium text-sm md:text-base">
                        <li><i class="fas fa-check text-blue-500 mr-2"></i> Multiple Campuses</li>
                        <li><i class="fas fa-check text-blue-500 mr-2"></i> Custom Integrations</li>
                        <li><i class="fas fa-check text-blue-500 mr-2"></i> White-label Solutions</li>
                        <li><i class="fas fa-check text-blue-500 mr-2"></i> Dedicated Support</li>
                        <li><i class="fas fa-check text-blue-500 mr-2"></i> API Access</li>
                        <li><i class="fas fa-check text-blue-500 mr-2"></i> Enterprise Security</li>
                    </ul>
                    <button class="w-full py-3 md:py-4 border-2 border-slate-200 rounded-xl md:rounded-2xl font-bold hover:bg-slate-50 transition text-sm md:text-base">
                        Contact Sales
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- Existing Testimonials Section -->
    <section id="testimonials" class="py-16 md:py-32 bg-slate-50 px-4 md:px-6">
        <div class="container mx-auto">
            <div class="text-center mb-12 md:mb-24" data-aos="fade-up">
                <h3 class="text-2xl md:text-4xl lg:text-6xl font-black mb-4 md:mb-6">Voices of Success.</h3>
                <p class="text-slate-500 text-base md:text-xl max-w-2xl mx-auto">The stories behind the thousands of schools we empower across Africa.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 md:gap-8">
                <div class="bg-white p-6 md:p-10 rounded-2xl md:rounded-[3rem] shadow-lg md:shadow-xl border border-slate-100" data-aos="fade-up">
                    <div class="flex items-center space-x-4 mb-6 md:mb-8">
                        <img src="https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?auto=format&fit=crop&w-150&q=80" class="w-12 h-12 md:w-16 md:h-16 rounded-xl md:rounded-2xl object-cover shadow-lg">
                        <div>
                            <p class="font-bold text-slate-900 text-sm md:text-base">Dr. Helena Hills</p>
                            <p class="text-blue-600 text-[10px] font-black uppercase">Principal, Global Academy</p>
                        </div>
                    </div>
                    <p class="text-slate-600 italic leading-relaxed text-sm md:text-base">"Managing our financial leakage was impossible until AcademiX Pro. We saved ₦2M in our first term by automating fee collection."</p>
                </div>
                <!-- Additional testimonials... -->
            </div>
        </div>
    </section>

    <!-- NEW: Integration Partners Section -->
    <section class="py-10 md:py-20 bg-white px-4 md:px-6">
        <div class="container mx-auto">
            <div class="text-center mb-8 md:mb-12" data-aos="fade-up">
                <h3 class="text-xl md:text-2xl font-bold text-slate-700 mb-3 md:mb-4">Trusted by Leading Partners</h3>
                <p class="text-slate-500 text-sm md:text-base">Seamlessly integrated with Nigeria's top educational and financial institutions</p>
            </div>
            
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 md:gap-8 items-center" data-aos="fade-up">
                <div class="bg-slate-50 p-4 md:p-6 rounded-xl md:rounded-2xl flex items-center justify-center h-16 md:h-20">
                    <span class="font-bold text-slate-700 text-sm md:text-base">GTBank</span>
                </div>
                <div class="bg-slate-50 p-4 md:p-6 rounded-xl md:rounded-2xl flex items-center justify-center h-16 md:h-20">
                    <span class="font-bold text-slate-700 text-sm md:text-base">WAEC</span>
                </div>
                <div class="bg-slate-50 p-4 md:p-6 rounded-xl md:rounded-2xl flex items-center justify-center h-16 md:h-20">
                    <span class="font-bold text-slate-700 text-sm md:text-base">Interswitch</span>
                </div>
                <div class="bg-slate-50 p-4 md:p-6 rounded-xl md:rounded-2xl flex items-center justify-center h-16 md:h-20">
                    <span class="font-bold text-slate-700 text-sm md:text-base">First Bank</span>
                </div>
                <div class="bg-slate-50 p-4 md:p-6 rounded-xl md:rounded-2xl flex items-center justify-center h-16 md:h-20">
                    <span class="font-bold text-slate-700 text-sm md:text-base">JAMB</span>
                </div>
                <div class="bg-slate-50 p-4 md:p-6 rounded-xl md:rounded-2xl flex items-center justify-center h-16 md:h-20">
                    <span class="font-bold text-slate-700 text-sm md:text-base">Paystack</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Existing FAQ Section -->
    <section id="faq" class="py-16 md:py-32 bg-white px-4 md:px-6">
        <div class="container mx-auto max-w-4xl">
            <div class="text-center mb-12 md:mb-20" data-aos="fade-up">
                <h3 class="text-2xl md:text-4xl font-black mb-3 md:mb-4">Intelligent Support.</h3>
                <p class="text-slate-500 text-sm md:text-base">Frequently asked questions answered by our system.</p>
            </div>

            <div class="space-y-4 md:space-y-6">
                <div class="faq-item group bg-slate-50 rounded-xl md:rounded-[2.5rem] overflow-hidden border border-transparent hover:border-blue-200 transition-all">
                    <label class="flex items-center justify-between p-4 md:p-8 cursor-pointer">
                        <input type="checkbox" class="faq-trigger hidden">
                        <span class="text-sm md:text-lg font-bold text-slate-800">Is our school data protected from breaches?</span>
                        <i class="fas fa-chevron-down text-slate-400 transition-transform text-sm md:text-base"></i>
                    </label>
                    <div class="faq-content opacity-0">
                        <p class="text-slate-600 text-sm md:text-base">We use AES-256 bank-level encryption. Your data is backed up every 6 hours across 3 global cloud servers, ensuring 99.9% uptime and total security.</p>
                    </div>
                </div>
                <!-- Additional FAQs... -->
            </div>
        </div>
    </section>

    <!-- Enhanced Final CTA -->
    <section class="py-16 md:py-32 px-4 md:px-6 bg-slate-900 text-white relative overflow-hidden">
        <div class="container mx-auto text-center relative z-10">
            <h2 class="text-2xl md:text-4xl lg:text-7xl font-black mb-8 md:mb-12" data-aos="fade-up">
                Ready to join the <br class="hidden md:block"> 
                <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-500 to-rose-500">Elite Institution Hub?</span>
            </h2>
            <p class="text-base md:text-xl text-slate-300 max-w-2xl mx-auto mb-8 md:mb-12" data-aos="fade-up" data-aos-delay="100">
                Transform your school administration with Africa's most advanced educational platform.
            </p>
            <div class="flex flex-col sm:flex-row justify-center gap-4 md:gap-6" data-aos="fade-up" data-aos-delay="200">
                <button class="bg-white text-slate-900 px-6 md:px-12 py-3 md:py-6 rounded-xl md:rounded-3xl font-bold text-base md:text-xl hover:scale-105 transition shadow-lg md:shadow-2xl">
                    Start Free 30-Day Trial
                </button>
                <button class="bg-blue-600 text-white px-6 md:px-12 py-3 md:py-6 rounded-xl md:rounded-3xl font-bold text-base md:text-xl hover:scale-105 transition shadow-lg md:shadow-2xl">
                    <i class="fas fa-calendar-alt mr-2"></i> Schedule Demo
                </button>
            </div>
            <p class="mt-8 md:mt-12 text-slate-400 font-bold tracking-widest uppercase text-[10px] md:text-xs" data-aos="fade-up" data-aos-delay="300">
                Join 50,000+ Educators & Parents Transforming Education.
            </p>
        </div>
    </section>

    <footer class="bg-white pt-12 md:pt-24 pb-8 md:pb-12 border-t border-slate-100 px-4 md:px-6">
        <div class="container mx-auto grid grid-cols-1 md:grid-cols-4 gap-8 md:gap-16 mb-10 md:mb-20">
            <div class="col-span-1 md:col-span-2">
                <div class="flex items-center space-x-2 mb-6 md:mb-8">
                    <div class="w-8 h-8 md:w-10 md:h-10 bg-blue-600 rounded-lg md:rounded-xl flex items-center justify-center text-white shadow-lg">
                        <i class="fas fa-microchip text-sm md:text-base"></i>
                    </div>
                    <span class="text-lg md:text-2xl font-bold tracking-tight text-slate-900">AcademiX<span class="text-blue-600">Suite</span></span>
                </div>
                <p class="text-slate-500 text-sm md:text-lg max-w-sm">The digital backbone of Nigeria's most progressive schools. Security, Automation, and Intelligence combined.</p>
            </div>
            <div>
                <h5 class="font-black text-slate-900 uppercase text-xs tracking-widest mb-6 md:mb-8">Ecosystem</h5>
                <ul class="space-y-3 md:space-y-4 text-slate-500 font-bold text-sm">
                    <li><a href="#platform" class="hover:text-blue-600 transition">Platform</a></li>
                    <li><a href="#capabilities" class="hover:text-blue-600 transition">Capabilities</a></li>
                    <li><a href="#comparison" class="hover:text-blue-600 transition">Comparison</a></li>
                    <li><a href="#pricing" class="hover:text-blue-600 transition">Pricing</a></li>
                </ul>
            </div>
            <div>
                <h5 class="font-black text-slate-900 uppercase text-xs tracking-widest mb-6 md:mb-8">Connect</h5>
                <p class="text-slate-500 font-bold text-xs md:text-sm mb-3 md:mb-4">Lagos Office: Victoria Island</p>
                <p class="text-slate-500 font-bold text-xs md:text-sm mb-3 md:mb-4">Email: hello@aacademixsuite.com</p>
                <div class="flex space-x-3 md:space-x-4 text-slate-400 text-lg md:text-xl pt-3 md:pt-4">
                    <i class="fab fa-linkedin hover:text-blue-600 cursor-pointer"></i>
                    <i class="fab fa-twitter hover:text-blue-600 cursor-pointer"></i>
                    <i class="fab fa-instagram hover:text-blue-600 cursor-pointer"></i>
                </div>
            </div>
        </div>
        <div class="text-center pt-8 md:pt-12 border-t border-slate-100">
            <p class="text-slate-400 font-black text-[10px] uppercase tracking-[0.4em]">© 2026 AcademiX Pro. Engineering Educational Excellence.</p>
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

        // Outside the Box: Dashboard Interactive Logic Preview
        const filterPills = document.querySelectorAll('.filter-pill');
        filterPills.forEach(pill => {
            pill.addEventListener('click', () => {
                filterPills.forEach(p => p.classList.remove('active'));
                pill.classList.add('active');
            });
        });

        // FAQ Interactive Detail Animation Logic
        const faqTriggers = document.querySelectorAll('.faq-trigger');
        faqTriggers.forEach(trigger => {
            trigger.addEventListener('change', () => {
                const parent = trigger.closest('.faq-item');
                const icon = parent.querySelector('i');
                const content = parent.querySelector('.faq-content');
                
                if (trigger.checked) {
                    icon.style.transform = 'rotate(180deg)';
                    content.style.maxHeight = '500px';
                    content.style.opacity = '1';
                    content.style.padding = '1.5rem';
                } else {
                    icon.style.transform = 'rotate(0deg)';
                    content.style.maxHeight = '0';
                    content.style.opacity = '0';
                    content.style.padding = '0';
                }
            });
        });

        // NEW: Feature Tabs Interaction
        const featureTabs = document.querySelectorAll('.feature-tab');
        featureTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                featureTabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
            });
        });

        // NEW: Statistics Counter Animation
        function animateCounter(element) {
            const target = parseInt(element.getAttribute('data-count'));
            const current = parseInt(element.textContent);
            const increment = target / 20;
            
            let count = current;
            const timer = setInterval(() => {
                count += increment;
                if (count >= target) {
                    element.textContent = target;
                    clearInterval(timer);
                } else {
                    element.textContent = Math.floor(count);
                }
            }, 50);
        }

        // NEW: Intersection Observer for counter animation
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const statNumbers = entry.target.querySelectorAll('.stat-number');
                    statNumbers.forEach(num => {
                        animateCounter(num);
                    });
                }
            });
        }, { threshold: 0.5 });

        // Observe statistics section
        const statsSection = document.getElementById('capabilities');
        if (statsSection) {
            observer.observe(statsSection);
        }

        // NEW: Enhanced School Filter Logic
        const schoolFilter = {
            state: '',
            feeRange: '',
            type: '',
            curriculum: ''
        };

        function updateSchoolResults() {
            // Filter logic would go here
            console.log('Filter updated:', schoolFilter);
        }

        // Initialize event listeners
        document.addEventListener('DOMContentLoaded', () => {
            // Add hover effects to all layer cards
            document.querySelectorAll('.layer-card').forEach(card => {
                card.addEventListener('mouseenter', () => {
                    card.style.zIndex = '10';
                });
                card.addEventListener('mouseleave', () => {
                    card.style.zIndex = '1';
                });
            });
        });
    </script>
</body>
</html>