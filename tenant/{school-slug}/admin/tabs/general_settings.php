<?php
// tabs/general_settings.php
// This file contains the original general settings form
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