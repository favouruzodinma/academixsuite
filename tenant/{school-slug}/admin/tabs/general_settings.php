<?php
// tabs/general_settings.php
// This file contains the original general settings form
if (!function_exists('landingRowsToTextarea')) {
    function landingRowsToTextarea($rows, array $keys) {
        if (!is_array($rows)) {
            $rows = json_decode((string) $rows, true) ?: [];
        }

        $lines = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parts = [];
            foreach ($keys as $key) {
                $parts[] = trim((string) ($row[$key] ?? ''));
            }
            if (array_filter($parts)) {
                $lines[] = implode(' | ', $parts);
            }
        }
        return implode("\n", $lines);
    }
}

$landingProgramsText = landingRowsToTextarea($schoolDetails['landing_programs'] ?? [], ['title', 'description']);
$landingTestimonialsText = landingRowsToTextarea($schoolDetails['landing_testimonials'] ?? [], ['name', 'role', 'quote']);

if (!function_exists('generalProfileAssetUrl')) {
    function generalProfileAssetUrl($path) {
        $path = trim((string) $path);
        if ($path === '') {
            return '/tenant/assets/images/placeholder-logo.png';
        }
        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }
        return '/' . ltrim($path, '/');
    }
}

$profileContacts = is_array($profileContacts ?? null) ? $profileContacts : [];
$profileFacilities = is_array($profileFacilities ?? null) ? $profileFacilities : [];
$profileGallery = is_array($profileGallery ?? null) ? $profileGallery : [];
$profileReviews = is_array($profileReviews ?? null) ? $profileReviews : [];
$profileMetrics = is_array($profileMetrics ?? null) ? $profileMetrics : [
    'contacts' => count($profileContacts),
    'facilities' => count($profileFacilities),
    'gallery' => count($profileGallery),
    'pending_reviews' => count(array_filter($profileReviews, static function ($review) {
        return (int) ($review['is_approved'] ?? 0) === 0;
    }))
];
$profileTableStatus = is_array($profileTableStatus ?? null) ? $profileTableStatus : [];
$contactTypes = ['phone' => 'Phone', 'email' => 'Email', 'address' => 'Address', 'website' => 'Website', 'whatsapp' => 'WhatsApp', 'social' => 'Social'];
$facilityIcons = [
    'school' => 'School',
    'book' => 'Library',
    'flask' => 'Science Lab',
    'monitor' => 'ICT Center',
    'sports' => 'Sports',
    'bus' => 'Transport',
    'restaurant' => 'Meal Program',
    'shield' => 'Security',
    'home' => 'Boarding',
    'heart' => 'Care'
];
$galleryTypes = ['campus' => 'Campus', 'classroom' => 'Classroom', 'laboratory' => 'Laboratory', 'library' => 'Library', 'sports' => 'Sports', 'events' => 'Events', 'other' => 'Other'];
$publicProfileHost = (string) ($schoolDetails['slug'] ?? $schoolSlug ?? '');
$publicProfileUrl = 'https://' . $publicProfileHost . '.academixsuite.com/';
$profileCsrfToken = $profileCsrfToken ?? ($_SESSION['general_profile_csrf'] ?? '');
?>

<style>
    .public-profile-console {
        display: grid;
        gap: 24px;
        margin-top: 24px;
    }
    .profile-command {
        border: 1px solid #e7eaf3;
        border-radius: 16px;
        background: linear-gradient(135deg, #101828 0%, #1f3560 52%, #25A194 100%);
        color: #fff;
        padding: 28px;
        overflow: hidden;
        position: relative;
    }
    .profile-command::after {
        content: "";
        position: absolute;
        width: 240px;
        height: 240px;
        border-radius: 50%;
        right: -80px;
        top: -100px;
        border: 44px solid rgba(255, 255, 255, 0.08);
    }
    .profile-command-inner {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 24px;
        align-items: center;
        position: relative;
        z-index: 1;
    }
    .profile-school-mark {
        width: 78px;
        height: 78px;
        border-radius: 20px;
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.22);
        object-fit: contain;
        padding: 8px;
    }
    .profile-command-eyebrow {
        color: rgba(255, 255, 255, 0.74);
        font-size: 12px;
        font-weight: 800;
        letter-spacing: .08em;
        text-transform: uppercase;
        margin-bottom: 8px;
    }
    .profile-command h4 {
        color: #fff;
        font-size: 26px;
        margin: 0 0 8px;
    }
    .profile-command p {
        color: rgba(255, 255, 255, 0.82);
        max-width: 720px;
        margin: 0;
    }
    .profile-command-actions {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 22px;
    }
    .profile-command-actions a,
    .profile-command-actions span {
        border-radius: 999px;
        padding: 10px 16px;
        font-size: 13px;
        font-weight: 800;
        text-decoration: none;
    }
    .profile-command-actions a {
        background: #fff;
        color: #101828;
    }
    .profile-command-actions span {
        border: 1px solid rgba(255, 255, 255, 0.24);
        color: rgba(255, 255, 255, 0.82);
    }
    .profile-metric-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
    }
    .profile-metric {
        background: #fff;
        border: 1px solid #edf0f7;
        border-radius: 14px;
        padding: 18px;
        box-shadow: 0 12px 32px rgba(16, 24, 40, 0.06);
    }
    .profile-metric span {
        display: block;
        color: #667085;
        font-size: 12px;
        font-weight: 700;
        margin-bottom: 8px;
    }
    .profile-metric strong {
        color: #101828;
        font-size: 28px;
        line-height: 1;
    }
    .profile-panel {
        background: #fff;
        border: 1px solid #edf0f7;
        border-radius: 16px;
        box-shadow: 0 12px 32px rgba(16, 24, 40, 0.04);
        overflow: hidden;
    }
    .profile-panel-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        padding: 22px 24px;
        border-bottom: 1px solid #edf0f7;
    }
    .profile-panel-header h5 {
        margin: 0 0 4px;
        color: #101828;
        font-size: 18px;
    }
    .profile-panel-header p {
        margin: 0;
        color: #667085;
        font-size: 13px;
    }
    .profile-panel-body {
        padding: 24px;
    }
    .profile-table {
        min-width: 840px;
        margin-bottom: 0;
    }
    .profile-table th {
        color: #667085;
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .04em;
        border-bottom-color: #edf0f7;
        background: #f8fafc;
    }
    .profile-table td {
        vertical-align: middle;
        border-bottom-color: #edf0f7;
    }
    .profile-mini-input {
        min-width: 84px;
    }
    .profile-gallery-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 16px;
    }
    .profile-gallery-card {
        border: 1px solid #edf0f7;
        border-radius: 14px;
        overflow: hidden;
        background: #fff;
    }
    .profile-gallery-card img {
        width: 100%;
        height: 132px;
        object-fit: cover;
        display: block;
        background: #f1f5f9;
    }
    .profile-gallery-card figcaption {
        padding: 12px;
        min-height: 72px;
    }
    .profile-review-card {
        border: 1px solid #edf0f7;
        border-radius: 14px;
        padding: 16px;
        background: #fff;
    }
    .profile-review-meta {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 10px;
    }
    .profile-stars {
        color: #f59e0b;
        font-weight: 900;
        white-space: nowrap;
    }
    .profile-status {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 4px 10px;
        font-size: 11px;
        font-weight: 800;
    }
    .profile-status.approved {
        color: #027a48;
        background: #ecfdf3;
    }
    .profile-status.pending {
        color: #b54708;
        background: #fffaeb;
    }
    .profile-form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding-top: 18px;
    }
    @media (max-width: 991px) {
        .profile-command-inner {
            grid-template-columns: 1fr;
        }
        .profile-metric-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (max-width: 575px) {
        .profile-command {
            padding: 22px;
        }
        .profile-metric-grid {
            grid-template-columns: 1fr;
        }
        .profile-panel-header {
            display: block;
        }
    }
</style>

<div class="card">
    <div class="card-body">
        <form action="" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_general">
            
            <!-- General Information Tab -->
            <div class="tab-content" id="settingsTabsContent">
                <!-- General Information Tab -->
                <div class="tab-pane fade show active" id="general" role="tabpanel">
                    <h5 class="form-section-title">School Information</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="school_name" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    School Name <span class="text-danger-600">*</span>
                                </label>
                                <input type="text" class="form-control radius-8" id="school_name" name="school_name"
                                    value="<?php echo htmlspecialchars($schoolDetails['name'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="school_slug" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    School Slug (URL)
                                </label>
                                <input type="text" class="form-control radius-8" id="school_slug" 
                                    value="<?php echo htmlspecialchars($schoolDetails['slug'] ?? ''); ?>" readonly disabled>
                                <small class="text-secondary-light">This cannot be changed</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="school_type" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    School Type
                                </label>
                                <select class="form-control radius-8 form-select" id="school_type" name="school_type">
                                    <option value="nursery" <?php echo ($schoolDetails['school_type'] ?? '') == 'nursery' ? 'selected' : ''; ?>>Nursery</option>
                                    <option value="primary" <?php echo ($schoolDetails['school_type'] ?? '') == 'primary' ? 'selected' : ''; ?>>Primary</option>
                                    <option value="secondary" <?php echo ($schoolDetails['school_type'] ?? '') == 'secondary' ? 'selected' : ''; ?>>Secondary</option>
                                    <option value="comprehensive" <?php echo ($schoolDetails['school_type'] ?? '') == 'comprehensive' ? 'selected' : ''; ?>>Comprehensive</option>
                                    <option value="international" <?php echo ($schoolDetails['school_type'] ?? '') == 'international' ? 'selected' : ''; ?>>International</option>
                                    <option value="montessori" <?php echo ($schoolDetails['school_type'] ?? '') == 'montessori' ? 'selected' : ''; ?>>Montessori</option>
                                    <option value="boarding" <?php echo ($schoolDetails['school_type'] ?? '') == 'boarding' ? 'selected' : ''; ?>>Boarding</option>
                                    <option value="day" <?php echo ($schoolDetails['school_type'] ?? '') == 'day' ? 'selected' : ''; ?>>Day</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="curriculum" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Curriculum
                                </label>
                                <input type="text" class="form-control radius-8" id="curriculum" name="curriculum"
                                    value="<?php echo htmlspecialchars($schoolDetails['curriculum'] ?? 'Nigerian'); ?>"
                                    placeholder="e.g., Nigerian, British, American">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="establishment_year" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Establishment Year
                                </label>
                                <input type="number" class="form-control radius-8" id="establishment_year" name="establishment_year"
                                    value="<?php echo htmlspecialchars($schoolDetails['establishment_year'] ?? ''); ?>"
                                    min="1900" max="<?php echo date('Y'); ?>" placeholder="YYYY">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="principal_name" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Principal's Name
                                </label>
                                <input type="text" class="form-control radius-8" id="principal_name" name="principal_name"
                                    value="<?php echo htmlspecialchars($schoolDetails['principal_name'] ?? ''); ?>"
                                    placeholder="Enter principal's full name">
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="mb-20">
                                <label for="description" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    School Description
                                </label>
                                <textarea class="form-control radius-8" id="description" name="description" rows="3"
                                    placeholder="Brief description of your school"><?php echo htmlspecialchars($schoolDetails['description'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="mb-20">
                                <label for="mission_statement" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Mission Statement
                                </label>
                                <textarea class="form-control radius-8" id="mission_statement" name="mission_statement" rows="2"
                                    placeholder="Your school's mission"><?php echo htmlspecialchars($schoolDetails['mission_statement'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="mb-20">
                                <label for="vision_statement" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Vision Statement
                                </label>
                                <textarea class="form-control radius-8" id="vision_statement" name="vision_statement" rows="2"
                                    placeholder="Your school's vision"><?php echo htmlspecialchars($schoolDetails['vision_statement'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="mb-20">
                                <label for="principal_message" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Principal's Message
                                </label>
                                <textarea class="form-control radius-8" id="principal_message" name="principal_message" rows="3"
                                    placeholder="Message from the principal"><?php echo htmlspecialchars($schoolDetails['principal_message'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <h5 class="form-section-title mt-24">Public Landing Page</h5>
                    <div class="alert alert-info bg-info-50 border-info-200 text-info-700">
                        These fields control the public school website shown on the school subdomain homepage.
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="landing_badge_text" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Hero Badge Text
                                </label>
                                <input type="text" class="form-control radius-8" id="landing_badge_text" name="landing_badge_text"
                                    value="<?php echo htmlspecialchars($schoolDetails['landing_badge_text'] ?? 'Admissions open'); ?>"
                                    placeholder="Admissions open">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="landing_headline" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Hero Headline
                                </label>
                                <input type="text" class="form-control radius-8" id="landing_headline" name="landing_headline"
                                    value="<?php echo htmlspecialchars($schoolDetails['landing_headline'] ?? ''); ?>"
                                    placeholder="Interactive learning that students love">
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="mb-20">
                                <label for="landing_subheadline" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Hero Supporting Text
                                </label>
                                <textarea class="form-control radius-8" id="landing_subheadline" name="landing_subheadline" rows="2"
                                    placeholder="Short, warm copy for parents and students."><?php echo htmlspecialchars($schoolDetails['landing_subheadline'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="landing_primary_cta_text" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Primary Button Text
                                </label>
                                <input type="text" class="form-control radius-8" id="landing_primary_cta_text" name="landing_primary_cta_text"
                                    value="<?php echo htmlspecialchars($schoolDetails['landing_primary_cta_text'] ?? 'Apply Now'); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="landing_secondary_cta_text" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Secondary Button Text
                                </label>
                                <input type="text" class="form-control radius-8" id="landing_secondary_cta_text" name="landing_secondary_cta_text"
                                    value="<?php echo htmlspecialchars($schoolDetails['landing_secondary_cta_text'] ?? 'Portal Login'); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="landing_intro_title" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Intro Section Title
                                </label>
                                <input type="text" class="form-control radius-8" id="landing_intro_title" name="landing_intro_title"
                                    value="<?php echo htmlspecialchars($schoolDetails['landing_intro_title'] ?? ''); ?>"
                                    placeholder="Learning made personal, joyful, and measurable">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="landing_highlight_title" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Highlight Section Title
                                </label>
                                <input type="text" class="form-control radius-8" id="landing_highlight_title" name="landing_highlight_title"
                                    value="<?php echo htmlspecialchars($schoolDetails['landing_highlight_title'] ?? ''); ?>"
                                    placeholder="Explore our educational world">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="landing_intro_text" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Intro Section Text
                                </label>
                                <textarea class="form-control radius-8" id="landing_intro_text" name="landing_intro_text" rows="3"><?php echo htmlspecialchars($schoolDetails['landing_intro_text'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="landing_highlight_text" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Highlight Section Text
                                </label>
                                <textarea class="form-control radius-8" id="landing_highlight_text" name="landing_highlight_text" rows="3"><?php echo htmlspecialchars($schoolDetails['landing_highlight_text'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="landing_hero_image" class="form-label fw-semibold text-secondary-light text-md mb-8">
                                Hero Image <span class="text-secondary-light fw-normal">(used in the first screen)</span>
                            </label>
                            <input type="file" class="form-control radius-8" id="landing_hero_image" name="landing_hero_image" accept="image/jpeg,image/png,image/webp">
                            <?php if (!empty($schoolDetails['landing_hero_image'])): ?>
                                <small class="text-secondary-light d-block mt-2">Current: <?php echo htmlspecialchars($schoolDetails['landing_hero_image']); ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label for="landing_feature_image" class="form-label fw-semibold text-secondary-light text-md mb-8">
                                Feature Image <span class="text-secondary-light fw-normal">(program section)</span>
                            </label>
                            <input type="file" class="form-control radius-8" id="landing_feature_image" name="landing_feature_image" accept="image/jpeg,image/png,image/webp">
                            <?php if (!empty($schoolDetails['landing_feature_image'])): ?>
                                <small class="text-secondary-light d-block mt-2">Current: <?php echo htmlspecialchars($schoolDetails['landing_feature_image']); ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <div class="mb-20">
                                <label for="landing_programs" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Program Cards
                                </label>
                                <textarea class="form-control radius-8" id="landing_programs" name="landing_programs" rows="5"
                                    placeholder="ABCs & Reading | Build confident reading and writing skills.&#10;Math & Numbers | Practical numeracy for daily learning."><?php echo htmlspecialchars($landingProgramsText); ?></textarea>
                                <small class="text-secondary-light">One program per line. Format: Title | Description</small>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="mb-20">
                                <label for="landing_testimonials" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Parent Testimonials
                                </label>
                                <textarea class="form-control radius-8" id="landing_testimonials" name="landing_testimonials" rows="5"
                                    placeholder="Aiden Herz | Parent | My child has built so much confidence here."><?php echo htmlspecialchars($landingTestimonialsText); ?></textarea>
                                <small class="text-secondary-light">One testimonial per line. Format: Name | Role | Quote</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="landing_cta_title" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Bottom CTA Title
                                </label>
                                <input type="text" class="form-control radius-8" id="landing_cta_title" name="landing_cta_title"
                                    value="<?php echo htmlspecialchars($schoolDetails['landing_cta_title'] ?? ''); ?>"
                                    placeholder="Start your child's learning adventure today">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="landing_cta_text" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Bottom CTA Text
                                </label>
                                <textarea class="form-control radius-8" id="landing_cta_text" name="landing_cta_text" rows="2"><?php echo htmlspecialchars($schoolDetails['landing_cta_text'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <h5 class="form-section-title mt-24">Contact Information</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="school_email" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    School Email <span class="text-danger-600">*</span>
                                </label>
                                <input type="email" class="form-control radius-8" id="school_email" name="school_email"
                                    value="<?php echo htmlspecialchars($schoolDetails['email'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="school_phone" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Phone Number <span class="text-danger-600">*</span>
                                </label>
                                <input type="tel" class="form-control radius-8" id="school_phone" name="school_phone"
                                    value="<?php echo htmlspecialchars($schoolDetails['phone'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="website" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Website
                                </label>
                                <input type="url" class="form-control radius-8" id="website" name="website"
                                    value="<?php echo htmlspecialchars($schoolDetails['website'] ?? ''); ?>"
                                    placeholder="https://www.example.com">
                            </div>
                        </div>
                    </div>

                    <h5 class="form-section-title mt-24">Location Information</h5>
                    <div class="row">
                        <div class="col-12">
                            <div class="mb-20">
                                <label for="address" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Address <span class="text-danger-600">*</span>
                                </label>
                                <input type="text" class="form-control radius-8" id="address" name="address"
                                    value="<?php echo htmlspecialchars($schoolDetails['address'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-20">
                                <label for="city" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    City <span class="text-danger-600">*</span>
                                </label>
                                <input type="text" class="form-control radius-8" id="city" name="city"
                                    value="<?php echo htmlspecialchars($schoolDetails['city'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-20">
                                <label for="state" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    State/Province <span class="text-danger-600">*</span>
                                </label>
                                <input type="text" class="form-control radius-8" id="state" name="state"
                                    value="<?php echo htmlspecialchars($schoolDetails['state'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-20">
                                <label for="postal_code" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Postal Code
                                </label>
                                <input type="text" class="form-control radius-8" id="postal_code" name="postal_code"
                                    value="<?php echo htmlspecialchars($schoolDetails['postal_code'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="country" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Country <span class="text-danger-600">*</span>
                                </label>
                                <select class="form-control radius-8 form-select" id="country" name="country" required>
                                    <option value="">Select Country</option>
                                    <?php foreach ($countries as $country): ?>
                                    <option value="<?php echo $country; ?>" <?php echo ($schoolDetails['country'] ?? 'Nigeria') == $country ? 'selected' : ''; ?>>
                                        <?php echo $country; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="timezone" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Timezone
                                </label>
                                <select class="form-control radius-8 form-select" id="timezone" name="timezone">
                                    <?php foreach ($timezones as $tz): ?>
                                    <option value="<?php echo $tz; ?>" <?php echo ($schoolDetails['timezone'] ?? 'Africa/Lagos') == $tz ? 'selected' : ''; ?>>
                                        <?php echo $tz; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <h5 class="form-section-title mt-24">Academic Settings</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="currency" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Default Currency
                                </label>
                                <select class="form-control radius-8 form-select" id="currency" name="currency">
                                    <?php foreach ($currencies as $curr): ?>
                                    <option value="<?php echo $curr; ?>" <?php echo ($schoolDetails['currency'] ?? 'NGN') == $curr ? 'selected' : ''; ?>>
                                        <?php echo $curr; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="language" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Default Language
                                </label>
                                <select class="form-control radius-8 form-select" id="language" name="language">
                                    <?php foreach ($languages as $code => $name): ?>
                                    <option value="<?php echo $code; ?>" <?php echo ($schoolDetails['language'] ?? 'en') == $code ? 'selected' : ''; ?>>
                                        <?php echo $name; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <h5 class="form-section-title mt-24">Branding & Media</h5>
                    <div class="row gy-4">
                        <div class="col-md-6">
                            <label for="logo" class="form-label fw-semibold text-secondary-light text-md mb-8">
                                School Logo <span class="text-secondary-light fw-normal">(140px x 140px)</span>
                            </label>
                            <input type="file" class="form-control radius-8" id="logo" name="logo" accept="image/*">
                            <div class="avatar-upload mt-16">
                                <div class="avatar-preview">
                                    <div id="logoPreview" class="preview-image"
                                        style="background-image: url('<?php echo !empty($schoolDetails['logo_path']) ? '../../' . htmlspecialchars($schoolDetails['logo_path']) : 'https://academixsuite.com/tenant/assets/images/placeholder-logo.png'; ?>');">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="favicon" class="form-label fw-semibold text-secondary-light text-md mb-8">
                                Favicon <span class="text-secondary-light fw-normal">(32px x 32px)</span>
                            </label>
                            <input type="file" class="form-control radius-8" id="favicon" name="favicon" accept="image/*">
                            <div class="avatar-upload mt-16">
                                <div class="avatar-preview" style="width: 32px; height: 32px;">
                                    <div id="faviconPreview" class="preview-image"
                                        style="background-image: url('<?php echo !empty($schoolDetails['favicon_path']) ? '../../' . htmlspecialchars($schoolDetails['favicon_path']) : 'https://academixsuite.com/tenant/assets/images/favicon.png'; ?>');">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <h5 class="form-section-title mt-24">Social Links</h5>
                    <div class="row">
                        <?php 
                        $socialLinks = !empty($schoolDetails['social_links']) 
                            ? (is_array($schoolDetails['social_links']) ? $schoolDetails['social_links'] : json_decode($schoolDetails['social_links'], true))
                            : [];
                        ?>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="facebook" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    <i class="ri-facebook-circle-fill text-primary me-2"></i>Facebook
                                </label>
                                <input type="url" class="form-control radius-8" id="facebook" name="facebook"
                                    value="<?php echo htmlspecialchars($socialLinks['facebook'] ?? ''); ?>"
                                    placeholder="https://facebook.com/your-school">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="twitter" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    <i class="ri-twitter-x-fill text-primary me-2"></i>Twitter/X
                                </label>
                                <input type="url" class="form-control radius-8" id="twitter" name="twitter"
                                    value="<?php echo htmlspecialchars($socialLinks['twitter'] ?? ''); ?>"
                                    placeholder="https://twitter.com/your-school">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="instagram" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    <i class="ri-instagram-fill text-primary me-2"></i>Instagram
                                </label>
                                <input type="url" class="form-control radius-8" id="instagram" name="instagram"
                                    value="<?php echo htmlspecialchars($socialLinks['instagram'] ?? ''); ?>"
                                    placeholder="https://instagram.com/your-school">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="linkedin" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    <i class="ri-linkedin-fill text-primary me-2"></i>LinkedIn
                                </label>
                                <input type="url" class="form-control radius-8" id="linkedin" name="linkedin"
                                    value="<?php echo htmlspecialchars($socialLinks['linkedin'] ?? ''); ?>"
                                    placeholder="https://linkedin.com/school/your-school">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-20">
                                <label for="youtube" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    <i class="ri-youtube-fill text-primary me-2"></i>YouTube
                                </label>
                                <input type="url" class="form-control radius-8" id="youtube" name="youtube"
                                    value="<?php echo htmlspecialchars($socialLinks['youtube'] ?? ''); ?>"
                                    placeholder="https://youtube.com/@your-school">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex align-items-center justify-content-center gap-3 mt-24">
                <button type="reset"
                    class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-40 py-11 radius-8">
                    Reset
                </button>
                <button type="submit"
                    class="btn btn-primary-600 border border-primary-600 text-md px-24 py-12 radius-8">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<?php
$missingProfileTables = [];
foreach ($profileTableStatus as $tableName => $exists) {
    if (!$exists) {
        $missingProfileTables[] = $tableName;
    }
}
$contactRows = array_values($profileContacts);
for ($i = 0; $i < 3; $i++) {
    $contactRows[] = [];
}
$facilityRows = array_values($profileFacilities);
for ($i = 0; $i < 3; $i++) {
    $facilityRows[] = [];
}
?>

<div class="public-profile-console">
    <section class="profile-command">
        <div class="profile-command-inner">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <img class="profile-school-mark" src="<?php echo htmlspecialchars(generalProfileAssetUrl($schoolDetails['logo_path'] ?? '')); ?>" alt="School logo">
                <div>
                    <div class="profile-command-eyebrow">Public website control</div>
                    <h4><?php echo htmlspecialchars($schoolDetails['name'] ?? 'School'); ?> profile</h4>
                    <p>
                        Manage the professional details shown on the school subdomain: official contact rows, facilities,
                        campus gallery, and parent reviews pulled from the platform database.
                    </p>
                    <div class="profile-command-actions">
                        <a href="<?php echo htmlspecialchars($publicProfileUrl); ?>" target="_blank" rel="noopener">
                            <i class="ri-external-link-line me-1"></i> View public page
                        </a>
                        <span><?php echo htmlspecialchars($schoolDetails['slug'] ?? $schoolSlug ?? 'school'); ?>.academixsuite.com</span>
                    </div>
                </div>
            </div>
            <div style="min-width: 260px;">
                <div class="profile-metric-grid" style="grid-template-columns: repeat(2, minmax(0, 1fr));">
                    <div class="profile-metric">
                        <span>Contacts</span>
                        <strong><?php echo (int) ($profileMetrics['contacts'] ?? 0); ?></strong>
                    </div>
                    <div class="profile-metric">
                        <span>Facilities</span>
                        <strong><?php echo (int) ($profileMetrics['facilities'] ?? 0); ?></strong>
                    </div>
                    <div class="profile-metric">
                        <span>Gallery</span>
                        <strong><?php echo (int) ($profileMetrics['gallery'] ?? 0); ?></strong>
                    </div>
                    <div class="profile-metric">
                        <span>Pending</span>
                        <strong><?php echo (int) ($profileMetrics['pending_reviews'] ?? 0); ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php if (!empty($missingProfileTables)): ?>
        <div class="alert alert-warning bg-warning-50 border-warning-200 text-warning-700">
            Missing platform table(s): <?php echo htmlspecialchars(implode(', ', $missingProfileTables)); ?>.
            Create them from <code>database/migrations/2026_05_18_school_profile_columns.sql</code> so this public profile manager can save data.
        </div>
    <?php endif; ?>

    <section class="profile-panel">
        <div class="profile-panel-header">
            <div>
                <h5>School Contacts</h5>
                <p>Add official contact points for admissions, accounts, location, social links, and main office support.</p>
            </div>
            <span class="badge bg-primary-50 text-primary-600 radius-8">Platform table: school_contacts</span>
        </div>
        <div class="profile-panel-body">
            <form action="" method="POST">
                <input type="hidden" name="action" value="save_school_contacts">
                <input type="hidden" name="profile_csrf_token" value="<?php echo htmlspecialchars($profileCsrfToken); ?>">
                <div class="table-responsive">
                    <table class="table profile-table">
                        <thead>
                            <tr>
                                <th style="width: 150px;">Type</th>
                                <th>Label</th>
                                <th>Value</th>
                                <th style="width: 120px;">Primary</th>
                                <th style="width: 110px;">Order</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contactRows as $index => $contact): ?>
                                <tr>
                                    <td>
                                        <select class="form-control radius-8 form-select" name="contacts[<?php echo $index; ?>][type]">
                                            <?php foreach ($contactTypes as $typeValue => $typeLabel): ?>
                                                <option value="<?php echo htmlspecialchars($typeValue); ?>" <?php echo ($contact['type'] ?? 'phone') === $typeValue ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($typeLabel); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" class="form-control radius-8" name="contacts[<?php echo $index; ?>][label]"
                                            value="<?php echo htmlspecialchars($contact['label'] ?? ''); ?>" placeholder="Admissions Office">
                                    </td>
                                    <td>
                                        <input type="text" class="form-control radius-8" name="contacts[<?php echo $index; ?>][value]"
                                            value="<?php echo htmlspecialchars($contact['value'] ?? ''); ?>" placeholder="+234... or info@example.com">
                                    </td>
                                    <td>
                                        <select class="form-control radius-8 form-select" name="contacts[<?php echo $index; ?>][is_primary]">
                                            <option value="0" <?php echo empty($contact['is_primary']) ? 'selected' : ''; ?>>No</option>
                                            <option value="1" <?php echo !empty($contact['is_primary']) ? 'selected' : ''; ?>>Yes</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" class="form-control radius-8 profile-mini-input" name="contacts[<?php echo $index; ?>][sort_order]"
                                            value="<?php echo htmlspecialchars((string) ($contact['sort_order'] ?? $index)); ?>" min="0">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="profile-form-actions">
                    <button type="submit" class="btn btn-primary-600 px-24 py-12 radius-8">
                        <i class="ri-save-3-line me-1"></i> Save contacts
                    </button>
                </div>
            </form>
        </div>
    </section>

    <section class="profile-panel">
        <div class="profile-panel-header">
            <div>
                <h5>Facilities & Highlights</h5>
                <p>Show parents the practical strengths of the school: labs, library, ICT, transport, boarding, meals, and care services.</p>
            </div>
            <span class="badge bg-success-50 text-success-600 radius-8">Platform table: school_facilities</span>
        </div>
        <div class="profile-panel-body">
            <form action="" method="POST">
                <input type="hidden" name="action" value="save_school_facilities">
                <input type="hidden" name="profile_csrf_token" value="<?php echo htmlspecialchars($profileCsrfToken); ?>">
                <div class="table-responsive">
                    <table class="table profile-table">
                        <thead>
                            <tr>
                                <th style="width: 170px;">Icon</th>
                                <th style="width: 220px;">Facility</th>
                                <th>Description</th>
                                <th style="width: 110px;">Visible</th>
                                <th style="width: 100px;">Order</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($facilityRows as $index => $facility): ?>
                                <tr>
                                    <td>
                                        <select class="form-control radius-8 form-select" name="facilities[<?php echo $index; ?>][icon]">
                                            <?php foreach ($facilityIcons as $iconValue => $iconLabel): ?>
                                                <option value="<?php echo htmlspecialchars($iconValue); ?>" <?php echo ($facility['icon'] ?? 'school') === $iconValue ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($iconLabel); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" class="form-control radius-8" name="facilities[<?php echo $index; ?>][name]"
                                            value="<?php echo htmlspecialchars($facility['name'] ?? ''); ?>" placeholder="Science Laboratory">
                                    </td>
                                    <td>
                                        <input type="text" class="form-control radius-8" name="facilities[<?php echo $index; ?>][description]"
                                            value="<?php echo htmlspecialchars($facility['description'] ?? ''); ?>" placeholder="Modern practical learning space">
                                    </td>
                                    <td>
                                        <select class="form-control radius-8 form-select" name="facilities[<?php echo $index; ?>][is_active]">
                                            <option value="1" <?php echo (int) ($facility['is_active'] ?? 1) === 1 ? 'selected' : ''; ?>>Yes</option>
                                            <option value="0" <?php echo isset($facility['is_active']) && (int) $facility['is_active'] === 0 ? 'selected' : ''; ?>>No</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" class="form-control radius-8 profile-mini-input" name="facilities[<?php echo $index; ?>][sort_order]"
                                            value="<?php echo htmlspecialchars((string) ($facility['sort_order'] ?? $index)); ?>" min="0">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="profile-form-actions">
                    <button type="submit" class="btn btn-primary-600 px-24 py-12 radius-8">
                        <i class="ri-save-3-line me-1"></i> Save facilities
                    </button>
                </div>
            </form>
        </div>
    </section>

    <section class="profile-panel">
        <div class="profile-panel-header">
            <div>
                <h5>School Gallery</h5>
                <p>Upload campus, classroom, lab, library, sports, and event images for the public landing page.</p>
            </div>
            <span class="badge bg-info-50 text-info-600 radius-8">Platform table: school_gallery</span>
        </div>
        <div class="profile-panel-body">
            <form action="" method="POST" enctype="multipart/form-data" class="mb-24">
                <input type="hidden" name="action" value="upload_school_gallery">
                <input type="hidden" name="profile_csrf_token" value="<?php echo htmlspecialchars($profileCsrfToken); ?>">
                <div class="row gy-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-primary-light text-sm mb-8">Upload Images</label>
                        <input type="file" class="form-control radius-8" name="gallery_images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-primary-light text-sm mb-8">Image Type</label>
                        <select class="form-control radius-8 form-select" name="gallery_type">
                            <?php foreach ($galleryTypes as $typeValue => $typeLabel): ?>
                                <option value="<?php echo htmlspecialchars($typeValue); ?>"><?php echo htmlspecialchars($typeLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-primary-light text-sm mb-8">Caption</label>
                        <input type="text" class="form-control radius-8" name="gallery_caption" placeholder="Campus entrance">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold text-primary-light text-sm mb-8">Order</label>
                        <input type="number" class="form-control radius-8" name="gallery_sort_order" min="0" value="<?php echo count($profileGallery) + 1; ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold text-primary-light text-sm mb-8">Optional Existing Image URL/Path</label>
                        <input type="text" class="form-control radius-8" name="gallery_image_url" placeholder="/assets/uploads/schools/1/gallery/photo.webp or https://...">
                    </div>
                </div>
                <div class="profile-form-actions">
                    <button type="submit" class="btn btn-primary-600 px-24 py-12 radius-8">
                        <i class="ri-upload-cloud-2-line me-1"></i> Add gallery image
                    </button>
                </div>
            </form>

            <?php if (empty($profileGallery)): ?>
                <div class="alert alert-light border text-secondary-light mb-0">No gallery images have been added yet.</div>
            <?php else: ?>
                <div class="profile-gallery-grid">
                    <?php foreach ($profileGallery as $image): ?>
                        <figure class="profile-gallery-card">
                            <img src="<?php echo htmlspecialchars(generalProfileAssetUrl($image['image_url'] ?? '')); ?>" alt="<?php echo htmlspecialchars($image['caption'] ?? 'School gallery image'); ?>">
                            <figcaption>
                                <div class="fw-semibold text-primary-light mb-1"><?php echo htmlspecialchars($image['caption'] ?? 'Untitled image'); ?></div>
                                <div class="text-secondary-light text-xs mb-2"><?php echo htmlspecialchars(ucfirst((string) ($image['type'] ?? 'campus'))); ?></div>
                                <form action="" method="POST">
                                    <input type="hidden" name="action" value="delete_school_gallery_item">
                                    <input type="hidden" name="profile_csrf_token" value="<?php echo htmlspecialchars($profileCsrfToken); ?>">
                                    <input type="hidden" name="gallery_id" value="<?php echo (int) ($image['id'] ?? 0); ?>">
                                    <button type="submit" class="btn btn-danger-600 btn-sm radius-8">
                                        <i class="ri-delete-bin-line me-1"></i> Remove
                                    </button>
                                </form>
                            </figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="profile-panel">
        <div class="profile-panel-header">
            <div>
                <h5>Parent Reviews</h5>
                <p>Approve reviews that should appear publicly. Pending or hidden reviews stay inside the platform database.</p>
            </div>
            <span class="badge bg-warning-50 text-warning-600 radius-8">Platform table: school_reviews</span>
        </div>
        <div class="profile-panel-body">
            <?php if (empty($profileReviews)): ?>
                <div class="alert alert-light border text-secondary-light mb-0">No parent reviews are available yet.</div>
            <?php else: ?>
                <div class="row gy-3">
                    <?php foreach ($profileReviews as $review): ?>
                        <?php
                        $reviewApproved = (int) ($review['is_approved'] ?? 0) === 1;
                        $reviewRating = (float) ($review['rating'] ?? 0);
                        ?>
                        <div class="col-xl-6">
                            <div class="profile-review-card">
                                <div class="profile-review-meta">
                                    <div>
                                        <div class="fw-semibold text-primary-light"><?php echo htmlspecialchars($review['parent_name'] ?? 'Parent'); ?></div>
                                        <div class="text-secondary-light text-xs"><?php echo htmlspecialchars($review['parent_email'] ?? ''); ?></div>
                                    </div>
                                    <span class="profile-status <?php echo $reviewApproved ? 'approved' : 'pending'; ?>">
                                        <?php echo $reviewApproved ? 'Approved' : 'Pending'; ?>
                                    </span>
                                </div>
                                <div class="profile-stars mb-2">
                                    <?php echo htmlspecialchars(number_format($reviewRating, 1)); ?> / 5.0
                                </div>
                                <?php if (!empty($review['title'])): ?>
                                    <div class="fw-semibold text-primary-light mb-1"><?php echo htmlspecialchars($review['title']); ?></div>
                                <?php endif; ?>
                                <p class="text-secondary-light text-sm mb-3"><?php echo htmlspecialchars($review['comment'] ?? ''); ?></p>
                                <?php if (!empty($review['student_name'])): ?>
                                    <div class="text-xs text-secondary-light mb-3">Student: <?php echo htmlspecialchars($review['student_name']); ?></div>
                                <?php endif; ?>
                                <form action="" method="POST" class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                                    <input type="hidden" name="action" value="moderate_school_review">
                                    <input type="hidden" name="profile_csrf_token" value="<?php echo htmlspecialchars($profileCsrfToken); ?>">
                                    <input type="hidden" name="review_id" value="<?php echo (int) ($review['id'] ?? 0); ?>">
                                    <label class="d-flex align-items-center gap-2 mb-0 text-sm text-secondary-light">
                                        <input type="checkbox" name="is_verified" value="1" <?php echo !empty($review['is_verified']) ? 'checked' : ''; ?>>
                                        Verified parent
                                    </label>
                                    <?php if ($reviewApproved): ?>
                                        <button type="submit" name="moderation" value="hide" class="btn btn-outline-danger btn-sm radius-8">
                                            Hide review
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" name="moderation" value="approve" class="btn btn-success-600 btn-sm radius-8">
                                            Approve review
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>
