<?php
/**
 * Add editable public landing page fields to schools.
 *
 * Run once from CLI:
 * php database/migrations/004_add_school_landing_page_columns.php
 */

require_once __DIR__ . '/../../includes/autoload.php';

$db = Database::getPlatformConnection();
$columns = $db->query("SHOW COLUMNS FROM schools")->fetchAll(PDO::FETCH_COLUMN, 0);

$definitions = [
    'landing_badge_text' => "VARCHAR(180) NULL AFTER secondary_color",
    'landing_headline' => "VARCHAR(255) NULL AFTER landing_badge_text",
    'landing_subheadline' => "TEXT NULL AFTER landing_headline",
    'landing_primary_cta_text' => "VARCHAR(80) NULL AFTER landing_subheadline",
    'landing_secondary_cta_text' => "VARCHAR(80) NULL AFTER landing_primary_cta_text",
    'landing_intro_title' => "VARCHAR(255) NULL AFTER landing_secondary_cta_text",
    'landing_intro_text' => "TEXT NULL AFTER landing_intro_title",
    'landing_highlight_title' => "VARCHAR(255) NULL AFTER landing_intro_text",
    'landing_highlight_text' => "TEXT NULL AFTER landing_highlight_title",
    'landing_hero_image' => "VARCHAR(500) NULL AFTER landing_highlight_text",
    'landing_feature_image' => "VARCHAR(500) NULL AFTER landing_hero_image",
    'landing_cta_title' => "VARCHAR(255) NULL AFTER landing_feature_image",
    'landing_cta_text' => "TEXT NULL AFTER landing_cta_title",
    'landing_programs' => "LONGTEXT NULL AFTER landing_cta_text",
    'landing_testimonials' => "LONGTEXT NULL AFTER landing_programs",
    'landing_updated_at' => "TIMESTAMP NULL DEFAULT NULL AFTER landing_testimonials"
];

foreach ($definitions as $column => $definition) {
    if (!in_array($column, $columns, true)) {
        $db->exec("ALTER TABLE schools ADD COLUMN {$column} {$definition}");
        echo "Added schools.{$column}\n";
    } else {
        echo "schools.{$column} already exists\n";
    }
}
