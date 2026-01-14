<?php
return [
    'mode' => 'utf-8',
    'format' => 'A4',
    'default_font_size' => 10,
    'default_font' => 'dejavusans',
    'margin_left' => 15,
    'margin_right' => 15,
    'margin_top' => 16,
    'margin_bottom' => 16,
    'margin_header' => 9,
    'margin_footer' => 9,
    'orientation' => 'P',
    
    'watermark' => [
        'text' => 'AcademixSuite',
        'alpha' => 0.1,
        'size' => 50,
    ],
    
    'header' => [
        'enabled' => true,
        'logo' => 'assets/images/logo.png',
        'logo_width' => 30,
        'line' => true,
    ],
    
    'footer' => [
        'enabled' => true,
        'text' => 'Page {PAGENO} of {nbpg}',
        'line' => true,
    ],
    
    'security' => [
        'encryption' => false,
        'permissions' => [
            'print' => true,
            'modify' => false,
            'copy' => true,
            'annot-forms' => true,
        ],
        'user_pass' => '',
        'owner_pass' => '',
    ],
];