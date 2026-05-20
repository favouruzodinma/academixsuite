<?php
// tabs/general_settings.php
// This file contains the original general settings form
$languages = is_array($languages ?? null) ? $languages : [
    'en' => 'English',
    'fr' => 'French',
    'es' => 'Spanish',
    'ar' => 'Arabic',
    'pt' => 'Portuguese',
    'yo' => 'Yoruba',
    'ig' => 'Igbo',
    'ha' => 'Hausa',
];
?>

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
                                <label for="description" class="form-label fw-semibold text-primary-light text-sm mb-8 d-flex align-items-center justify-content-between">
                                    School Description
                                    <button type="button" class="btn-ai-gen" data-field="description" data-label="School Description">
                                        <i class="ri-sparkling-line"></i> Generate with AI
                                    </button>
                                </label>
                                <textarea class="form-control radius-8" id="description" name="description" rows="3"
                                    placeholder="Brief description of your school"><?php echo htmlspecialchars($schoolDetails['description'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="mb-20">
                                <label for="mission_statement" class="form-label fw-semibold text-primary-light text-sm mb-8 d-flex align-items-center justify-content-between">
                                    Mission Statement
                                    <button type="button" class="btn-ai-gen" data-field="mission_statement" data-label="Mission Statement">
                                        <i class="ri-sparkling-line"></i> Generate with AI
                                    </button>
                                </label>
                                <textarea class="form-control radius-8" id="mission_statement" name="mission_statement" rows="2"
                                    placeholder="Your school's mission"><?php echo htmlspecialchars($schoolDetails['mission_statement'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="mb-20">
                                <label for="vision_statement" class="form-label fw-semibold text-primary-light text-sm mb-8 d-flex align-items-center justify-content-between">
                                    Vision Statement
                                    <button type="button" class="btn-ai-gen" data-field="vision_statement" data-label="Vision Statement">
                                        <i class="ri-sparkling-line"></i> Generate with AI
                                    </button>
                                </label>
                                <textarea class="form-control radius-8" id="vision_statement" name="vision_statement" rows="2"
                                    placeholder="Your school's vision"><?php echo htmlspecialchars($schoolDetails['vision_statement'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="mb-20">
                                <label for="principal_message" class="form-label fw-semibold text-primary-light text-sm mb-8 d-flex align-items-center justify-content-between">
                                    Principal's Message
                                    <button type="button" class="btn-ai-gen" data-field="principal_message" data-label="Principal's Message">
                                        <i class="ri-sparkling-line"></i> Generate with AI
                                    </button>
                                </label>
                                <textarea class="form-control radius-8" id="principal_message" name="principal_message" rows="3"
                                    placeholder="Message from the principal"><?php echo htmlspecialchars($schoolDetails['principal_message'] ?? ''); ?></textarea>
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

<!-- ══ AI Content Generator Panel ════════════════════════════════════════════ -->
<div id="aiGenPanel" role="dialog" aria-modal="true" aria-labelledby="aiGenPanelTitle" style="display:none">
    <div id="aiGenPanelInner">
        <div class="aig-header">
            <div class="aig-header-left">
                <span class="aig-icon"><i class="ri-sparkling-2-fill"></i></span>
                <div>
                    <div class="aig-title" id="aiGenPanelTitle">AI Content Generator</div>
                    <div class="aig-sub">Generating: <strong id="aig-field-label">—</strong></div>
                </div>
            </div>
            <button class="aig-close" id="aig-close-btn" aria-label="Close">&times;</button>
        </div>

        <div class="aig-body">
            <!-- Tone selector -->
            <div class="aig-row">
                <label class="aig-label" for="aig-tone">Writing tone</label>
                <select id="aig-tone" class="aig-select">
                    <option value="professional">Professional</option>
                    <option value="inspiring">Inspiring</option>
                    <option value="friendly">Friendly &amp; Warm</option>
                    <option value="formal">Formal / Academic</option>
                </select>
            </div>

            <!-- Optional hint -->
            <div class="aig-row">
                <label class="aig-label" for="aig-hint">Focus hint <span class="aig-optional">(optional)</span></label>
                <input type="text" id="aig-hint" class="aig-input"
                    placeholder="e.g. STEM-focused, faith-based, bilingual, over 500 students…">
            </div>

            <!-- Generate button -->
            <button id="aig-generate-btn" class="aig-btn-generate">
                <i class="ri-sparkling-line"></i>
                <span id="aig-btn-text">Generate</span>
            </button>

            <!-- Preview area -->
            <div id="aig-preview-wrap" style="display:none">
                <div class="aig-preview-label">Generated content</div>
                <div id="aig-preview-box" class="aig-preview-box" contenteditable="true" spellcheck="true"></div>
                <div class="aig-preview-actions">
                    <button id="aig-use-btn" class="aig-btn-use">
                        <i class="ri-check-line"></i> Use this
                    </button>
                    <button id="aig-regen-btn" class="aig-btn-regen">
                        <i class="ri-refresh-line"></i> Regenerate
                    </button>
                </div>
            </div>

            <!-- Error area -->
            <div id="aig-error" class="aig-error" style="display:none"></div>
        </div>
    </div>
</div>
<div id="aiGenOverlay" style="display:none"></div>

<style>
/* ── Generate button (inline with label) ─────────────────────────────── */
.btn-ai-gen {
    display:inline-flex;align-items:center;gap:5px;
    background:linear-gradient(135deg,#25A194 0%,#1a7a70 100%);
    color:#fff;border:none;border-radius:20px;
    padding:4px 12px;font-size:11px;font-weight:600;
    cursor:pointer;transition:opacity .15s,transform .1s;
    white-space:nowrap;line-height:1.4;flex-shrink:0;
}
.btn-ai-gen:hover { opacity:.88;transform:scale(1.03); }
.btn-ai-gen i { font-size:12px; }

/* ── Panel overlay ───────────────────────────────────────────────────── */
#aiGenOverlay {
    position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:1050;
    animation:aig-fadein .2s ease;
}
@keyframes aig-fadein { from{opacity:0} to{opacity:1} }

/* ── Panel ───────────────────────────────────────────────────────────── */
#aiGenPanel {
    position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
    z-index:1060;width:min(520px,94vw);
    animation:aig-slidein .25s cubic-bezier(.4,0,.2,1);
}
@keyframes aig-slidein {
    from{opacity:0;transform:translate(-50%,-48%)} to{opacity:1;transform:translate(-50%,-50%)}
}
#aiGenPanelInner {
    background:#fff;border-radius:16px;
    box-shadow:0 8px 40px rgba(0,0,0,.22);overflow:hidden;
}
.aig-header {
    background:linear-gradient(135deg,#25A194,#1a7a70);
    padding:16px 20px;display:flex;align-items:center;justify-content:space-between;
}
.aig-header-left { display:flex;align-items:center;gap:12px; }
.aig-icon {
    width:38px;height:38px;border-radius:50%;
    background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;
    font-size:18px;color:#fff;
}
.aig-title { font-weight:700;font-size:15px;color:#fff; }
.aig-sub   { font-size:11px;color:rgba(255,255,255,.8);margin-top:2px; }
.aig-sub strong { color:#fff; }
.aig-close {
    background:none;border:none;color:rgba(255,255,255,.7);
    font-size:22px;line-height:1;cursor:pointer;padding:0 4px;
}
.aig-close:hover { color:#fff; }

/* ── Panel body ──────────────────────────────────────────────────────── */
.aig-body { padding:20px; }
.aig-row  { margin-bottom:14px; }
.aig-label {
    display:block;font-size:12px;font-weight:600;color:#475569;
    text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;
}
.aig-optional { font-weight:400;text-transform:none;color:#94a3b8; }
.aig-select,.aig-input {
    width:100%;border:1.5px solid #e2e8f0;border-radius:8px;
    padding:8px 11px;font-size:13.5px;color:#1e293b;background:#f8fafc;
    outline:none;transition:border-color .15s;box-sizing:border-box;
    font-family:inherit;
}
.aig-select:focus,.aig-input:focus { border-color:#25A194;background:#fff; }

/* ── Generate button ─────────────────────────────────────────────────── */
.aig-btn-generate {
    width:100%;background:linear-gradient(135deg,#25A194,#1a7a70);
    color:#fff;border:none;border-radius:10px;padding:11px;
    font-size:14px;font-weight:700;cursor:pointer;
    display:flex;align-items:center;justify-content:center;gap:7px;
    transition:opacity .15s;margin-bottom:16px;
}
.aig-btn-generate:disabled { opacity:.55;cursor:default; }
.aig-btn-generate i { font-size:15px; }

/* ── Preview box ─────────────────────────────────────────────────────── */
.aig-preview-label {
    font-size:11px;font-weight:700;color:#64748b;
    text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;
}
.aig-preview-box {
    background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;
    padding:12px 14px;font-size:13.5px;line-height:1.65;color:#1e293b;
    min-height:80px;outline:none;transition:border-color .15s;
    white-space:pre-wrap;word-break:break-word;
}
.aig-preview-box:focus { border-color:#25A194;background:#fff; }
.aig-preview-actions {
    display:flex;gap:8px;margin-top:10px;
}
.aig-btn-use {
    flex:1;background:linear-gradient(135deg,#25A194,#1a7a70);
    color:#fff;border:none;border-radius:8px;padding:9px 14px;
    font-size:13px;font-weight:700;cursor:pointer;
    display:flex;align-items:center;justify-content:center;gap:6px;
    transition:opacity .15s;
}
.aig-btn-use:hover { opacity:.88; }
.aig-btn-regen {
    background:#fff;border:1.5px solid #e2e8f0;color:#475569;
    border-radius:8px;padding:8px 14px;font-size:13px;cursor:pointer;
    display:flex;align-items:center;gap:5px;transition:border-color .15s;
}
.aig-btn-regen:hover { border-color:#25A194;color:#25A194; }

/* ── Error ───────────────────────────────────────────────────────────── */
.aig-error {
    background:#fee2e2;color:#b91c1c;border-radius:8px;
    padding:10px 13px;font-size:13px;font-weight:500;
}

/* ── Spinner in button ───────────────────────────────────────────────── */
.aig-spinner {
    width:15px;height:15px;border-radius:50%;
    border:2px solid rgba(255,255,255,.4);border-top-color:#fff;
    animation:aig-spin .6s linear infinite;display:inline-block;
    vertical-align:middle;
}
@keyframes aig-spin { to{transform:rotate(360deg)} }
</style>

<script>
(function () {
    'use strict';

    const CSRF     = <?= json_encode($csrfToken ?? ($_SESSION['csrf_token'] ?? '')) ?>;
    const ENDPOINT = 'general.php';    // same page

    let _currentField = '';    // field name currently being generated for

    /* ── Open panel ────────────────────────────────────────────────────── */
    document.querySelectorAll('.btn-ai-gen').forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            _currentField = this.dataset.field;
            document.getElementById('aig-field-label').textContent = this.dataset.label;
            document.getElementById('aig-hint').value    = '';
            document.getElementById('aig-tone').value    = 'professional';
            document.getElementById('aig-preview-wrap').style.display = 'none';
            document.getElementById('aig-error').style.display        = 'none';
            document.getElementById('aig-preview-box').textContent    = '';

            document.getElementById('aiGenOverlay').style.display = 'block';
            document.getElementById('aiGenPanel').style.display   = 'block';
            document.getElementById('aig-hint').focus();
        });
    });

    /* ── Close panel ────────────────────────────────────────────────────── */
    function closePanel() {
        document.getElementById('aiGenPanel').style.display   = 'none';
        document.getElementById('aiGenOverlay').style.display = 'none';
        _currentField = '';
    }
    document.getElementById('aig-close-btn').addEventListener('click', closePanel);
    document.getElementById('aiGenOverlay').addEventListener('click', closePanel);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closePanel(); });

    /* ── Generate ────────────────────────────────────────────────────────── */
    function runGenerate() {
        if (!_currentField) return;

        const hint    = document.getElementById('aig-hint').value.trim();
        const tone    = document.getElementById('aig-tone').value;
        const btn     = document.getElementById('aig-generate-btn');
        const btnText = document.getElementById('aig-btn-text');
        const errEl   = document.getElementById('aig-error');
        const prevWrap = document.getElementById('aig-preview-wrap');
        const prevBox  = document.getElementById('aig-preview-box');

        // Loading state
        btn.disabled  = true;
        btnText.innerHTML = '<span class="aig-spinner"></span> Generating…';
        errEl.style.display  = 'none';
        prevWrap.style.display = 'none';

        // Strip 'pp_' prefix used on public_profile.php element IDs to get the
        // canonical API field name (e.g. pp_landing_headline → landing_headline)
        const apiField = _currentField.replace(/^pp_/, '');

        const fd = new FormData();
        fd.append('action',      'generate_profile_content');
        fd.append('csrf_token',  CSRF);
        fd.append('field',       apiField);
        fd.append('hint',        hint);
        fd.append('tone',        tone);

        fetch(ENDPOINT, {
            method:  'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body:    fd,
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.content) {
                prevBox.textContent    = data.content;
                prevWrap.style.display = 'block';
            } else {
                errEl.textContent    = '⚠ ' + (data.message || 'Generation failed. Please try again.');
                errEl.style.display  = 'block';
            }
        })
        .catch(() => {
            errEl.textContent   = '⚠ Network error. Check your connection and try again.';
            errEl.style.display = 'block';
        })
        .finally(() => {
            btn.disabled  = false;
            btnText.innerHTML = '<i class="ri-sparkling-line"></i> Generate';
        });
    }

    document.getElementById('aig-generate-btn').addEventListener('click', runGenerate);
    document.getElementById('aig-regen-btn').addEventListener('click', runGenerate);

    /* Allow pressing Enter in hint field to trigger generation */
    document.getElementById('aig-hint').addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); runGenerate(); }
    });

    /* ── Use this → copy into target textarea ────────────────────────────── */
    document.getElementById('aig-use-btn').addEventListener('click', function () {
        const content  = document.getElementById('aig-preview-box').textContent.trim();
        const targetEl = document.getElementById(_currentField);
        if (targetEl && content) {
            targetEl.value = content;
            // Trigger change event so any JS listeners pick it up
            targetEl.dispatchEvent(new Event('input', { bubbles: true }));
            targetEl.dispatchEvent(new Event('change', { bubbles: true }));
            closePanel();
            // Brief highlight on the populated field
            targetEl.style.transition = 'background .4s';
            targetEl.style.background = '#d1fae5';
            setTimeout(() => { targetEl.style.background = ''; }, 1200);
        }
    });
})();
</script>
