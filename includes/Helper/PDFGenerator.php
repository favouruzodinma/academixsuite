<?php
/**
 * PDF Generator
 * Generates PDF documents for invoices, receipts, reports, etc.
 */
namespace AcademixSuite\Helpers;

class PDFGenerator {
    
    private $config;
    private $pdf;
    private $fontDir;
    
    public function __construct() {
        require_once __DIR__ . '/../../vendor/autoload.php';
        
        $this->config = require __DIR__ . '/../../config/pdf.php';
        $this->fontDir = defined('FONT_DIR') ? FONT_DIR : __DIR__ . '/../../assets/fonts/';
        
        $this->initializePDF();
    }
    
    /**
     * Initialize PDF library
     */
    private function initializePDF(): void {
        $this->pdf = new \Mpdf\Mpdf([
            'mode' => $this->config['mode'] ?? 'utf-8',
            'format' => $this->config['format'] ?? 'A4',
            'default_font_size' => $this->config['default_font_size'] ?? 10,
            'default_font' => $this->config['default_font'] ?? 'dejavusans',
            'margin_left' => $this->config['margin_left'] ?? 15,
            'margin_right' => $this->config['margin_right'] ?? 15,
            'margin_top' => $this->config['margin_top'] ?? 16,
            'margin_bottom' => $this->config['margin_bottom'] ?? 16,
            'margin_header' => $this->config['margin_header'] ?? 9,
            'margin_footer' => $this->config['margin_footer'] ?? 9,
            'orientation' => $this->config['orientation'] ?? 'P'
        ]);
        
        // Set font directory
        if (is_dir($this->fontDir)) {
            $this->pdf->fontDir = [$this->fontDir];
        }
        
        // Set default styles
        $this->pdf->SetDisplayMode('fullpage');
        $this->pdf->SetAuthor('AcademixSuite');
        $this->pdf->SetCreator('AcademixSuite PDF Generator');
        $this->pdf->SetTitle('Document');
    }
    
    /**
     * Generate invoice PDF
     */
    public function generateInvoice(array $invoiceData): string {
        try {
            $this->pdf->AddPage();
            
            // Header
            $this->generateInvoiceHeader($invoiceData);
            
            // School and invoice info
            $this->generateInvoiceInfo($invoiceData);
            
            // Billing info
            $this->generateBillingInfo($invoiceData);
            
            // Items table
            $this->generateInvoiceItems($invoiceData);
            
            // Totals
            $this->generateInvoiceTotals($invoiceData);
            
            // Terms and conditions
            $this->generateInvoiceTerms($invoiceData);
            
            // Footer
            $this->generateInvoiceFooter($invoiceData);
            
            return $this->pdf->Output('', 'S');
            
        } catch (\Exception $e) {
            throw new \Exception("Failed to generate invoice PDF: " . $e->getMessage());
        }
    }
    
    /**
     * Generate receipt PDF
     */
    public function generateReceipt(array $paymentData): string {
        try {
            $this->pdf->AddPage();
            
            // Header
            $this->generateReceiptHeader($paymentData);
            
            // Payment info
            $this->generateReceiptInfo($paymentData);
            
            // Payment details
            $this->generatePaymentDetails($paymentData);
            
            // Footer
            $this->generateReceiptFooter($paymentData);
            
            return $this->pdf->Output('', 'S');
            
        } catch (\Exception $e) {
            throw new \Exception("Failed to generate receipt PDF: " . $e->getMessage());
        }
    }
    
    /**
     * Generate report PDF
     */
    public function generateReport(array $reportData, string $type = 'general'): string {
        try {
            $this->pdf->AddPage();
            
            switch ($type) {
                case 'financial':
                    $this->generateFinancialReport($reportData);
                    break;
                    
                case 'attendance':
                    $this->generateAttendanceReport($reportData);
                    break;
                    
                case 'academic':
                    $this->generateAcademicReport($reportData);
                    break;
                    
                default:
                    $this->generateGeneralReport($reportData);
            }
            
            return $this->pdf->Output('', 'S');
            
        } catch (\Exception $e) {
            throw new \Exception("Failed to generate report PDF: " . $e->getMessage());
        }
    }
    
    /**
     * Generate certificate PDF
     */
    public function generateCertificate(array $certificateData): string {
        try {
            $this->pdf->AddPage('L'); // Landscape for certificates
            
            // Set background if available
            if (isset($certificateData['background_image']) && file_exists($certificateData['background_image'])) {
                $this->pdf->SetDocTemplate($certificateData['background_image'], true);
            }
            
            // Certificate content
            $this->generateCertificateContent($certificateData);
            
            return $this->pdf->Output('', 'S');
            
        } catch (\Exception $e) {
            throw new \Exception("Failed to generate certificate PDF: " . $e->getMessage());
        }
    }
    
    /**
     * Generate student ID card
     */
    public function generateIdCard(array $studentData): string {
        try {
            $this->pdf->AddPage('', [85.6, 53.98]); // ID card size in mm
            
            // ID card content
            $this->generateIdCardContent($studentData);
            
            return $this->pdf->Output('', 'S');
            
        } catch (\Exception $e) {
            throw new \Exception("Failed to generate ID card PDF: " . $e->getMessage());
        }
    }
    
    /**
     * Generate invoice header
     */
    private function generateInvoiceHeader(array $data): void {
        $html = '
        <table width="100%" style="border-bottom: 2px solid #333; margin-bottom: 20px;">
            <tr>
                <td width="50%">
                    <h1 style="color: #2c3e50; margin: 0;">' . ($data['school']['name'] ?? 'AcademixSuite') . '</h1>
                    <p style="margin: 5px 0; color: #7f8c8d;">
                        ' . ($data['school']['address'] ?? '') . '<br>
                        Phone: ' . ($data['school']['phone'] ?? '') . '<br>
                        Email: ' . ($data['school']['email'] ?? '') . '
                    </p>
                </td>
                <td width="50%" style="text-align: right;">
                    <h2 style="color: #3498db; margin: 0; font-size: 24px;">INVOICE</h2>
                    <p style="margin: 5px 0; color: #7f8c8d;">
                        Invoice #: ' . ($data['invoice']['invoice_number'] ?? '') . '<br>
                        Date: ' . date('F j, Y', strtotime($data['invoice']['issue_date'] ?? 'now')) . '<br>
                        Due Date: ' . date('F j, Y', strtotime($data['invoice']['due_date'] ?? 'now')) . '
                    </p>
                </td>
            </tr>
        </table>';
        
        $this->pdf->WriteHTML($html);
    }
    
    /**
     * Generate invoice info
     */
    private function generateInvoiceInfo(array $data): void {
        $html = '
        <table width="100%" style="margin-bottom: 20px;">
            <tr>
                <td width="50%" style="vertical-align: top;">
                    <h3 style="color: #2c3e50; margin: 0 0 10px 0;">Bill To:</h3>
                    <p style="margin: 0; line-height: 1.5;">
                        <strong>' . ($data['parent']['name'] ?? '') . '</strong><br>
                        ' . ($data['parent']['address'] ?? '') . '<br>
                        Phone: ' . ($data['parent']['phone'] ?? '') . '<br>
                        Email: ' . ($data['parent']['email'] ?? '') . '
                    </p>
                </td>
                <td width="50%" style="vertical-align: top;">
                    <h3 style="color: #2c3e50; margin: 0 0 10px 0;">Student:</h3>
                    <p style="margin: 0; line-height: 1.5;">
                        <strong>' . ($data['student']['first_name'] ?? '') . ' ' . ($data['student']['last_name'] ?? '') . '</strong><br>
                        Admission #: ' . ($data['student']['admission_number'] ?? '') . '<br>
                        Class: ' . ($data['student']['class_name'] ?? '') . '<br>
                        Academic Year: ' . ($data['invoice']['academic_year'] ?? date('Y')) . '
                    </p>
                </td>
            </tr>
        </table>';
        
        $this->pdf->WriteHTML($html);
    }
    
    /**
     * Generate billing info
     */
    private function generateBillingInfo(array $data): void {
        $html = '
        <div style="margin-bottom: 20px;">
            <h3 style="color: #2c3e50; margin: 0 0 10px 0;">Billing Information</h3>
            <p style="margin: 0; line-height: 1.5; color: #7f8c8d;">
                ' . ($data['invoice']['description'] ?? 'School Fees for ' . date('F Y')) . '
            </p>
        </div>';
        
        $this->pdf->WriteHTML($html);
    }
    
    /**
     * Generate invoice items table
     */
    private function generateInvoiceItems(array $data): void {
        $html = '
        <table width="100%" style="border-collapse: collapse; margin-bottom: 20px;">
            <thead>
                <tr style="background-color: #3498db; color: white;">
                    <th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Description</th>
                    <th style="border: 1px solid #ddd; padding: 10px; text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>';
        
        $items = $data['items'] ?? [];
        foreach ($items as $item) {
            $html .= '
                <tr>
                    <td style="border: 1px solid #ddd; padding: 10px;">' . ($item['description'] ?? '') . '</td>
                    <td style="border: 1px solid #ddd; padding: 10px; text-align: right;">' . 
                        $this->formatCurrency($item['amount'] ?? 0, $data['invoice']['currency'] ?? 'NGN') . '</td>
                </tr>';
        }
        
        $html .= '
            </tbody>
        </table>';
        
        $this->pdf->WriteHTML($html);
    }
    
    /**
     * Generate invoice totals
     */
    private function generateInvoiceTotals(array $data): void {
        $subtotal = $data['invoice']['total_amount'] ?? 0;
        $discount = $data['invoice']['discount'] ?? 0;
        $tax = $data['invoice']['tax'] ?? 0;
        $total = $subtotal - $discount + $tax;
        $paid = $data['invoice']['paid_amount'] ?? 0;
        $balance = $data['invoice']['balance_amount'] ?? $total;
        
        $html = '
        <table width="100%" style="margin-bottom: 20px;">
            <tr>
                <td width="70%"></td>
                <td width="30%">
                    <table width="100%" style="border-collapse: collapse;">
                        <tr>
                            <td style="padding: 5px 10px; text-align: right;">Subtotal:</td>
                            <td style="padding: 5px 10px; text-align: right;">' . $this->formatCurrency($subtotal, $data['invoice']['currency'] ?? 'NGN') . '</td>
                        </tr>';
        
        if ($discount > 0) {
            $html .= '
                        <tr>
                            <td style="padding: 5px 10px; text-align: right;">Discount:</td>
                            <td style="padding: 5px 10px; text-align: right; color: #e74c3c;">-' . $this->formatCurrency($discount, $data['invoice']['currency'] ?? 'NGN') . '</td>
                        </tr>';
        }
        
        if ($tax > 0) {
            $html .= '
                        <tr>
                            <td style="padding: 5px 10px; text-align: right;">Tax:</td>
                            <td style="padding: 5px 10px; text-align: right;">' . $this->formatCurrency($tax, $data['invoice']['currency'] ?? 'NGN') . '</td>
                        </tr>';
        }
        
        $html .= '
                        <tr style="border-top: 2px solid #333;">
                            <td style="padding: 10px; text-align: right; font-weight: bold;">Total:</td>
                            <td style="padding: 10px; text-align: right; font-weight: bold;">' . $this->formatCurrency($total, $data['invoice']['currency'] ?? 'NGN') . '</td>
                        </tr>
                        <tr>
                            <td style="padding: 5px 10px; text-align: right;">Paid Amount:</td>
                            <td style="padding: 5px 10px; text-align: right; color: #27ae60;">' . $this->formatCurrency($paid, $data['invoice']['currency'] ?? 'NGN') . '</td>
                        </tr>
                        <tr style="border-top: 2px solid #3498db;">
                            <td style="padding: 10px; text-align: right; font-weight: bold; color: #3498db;">Balance Due:</td>
                            <td style="padding: 10px; text-align: right; font-weight: bold; color: #3498db;">' . $this->formatCurrency($balance, $data['invoice']['currency'] ?? 'NGN') . '</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>';
        
        $this->pdf->WriteHTML($html);
    }
    
    /**
     * Generate invoice terms
     */
    private function generateInvoiceTerms(array $data): void {
        $html = '
        <div style="margin-bottom: 30px; padding: 15px; background-color: #f8f9fa; border-left: 4px solid #3498db;">
            <h4 style="color: #2c3e50; margin: 0 0 10px 0;">Terms & Conditions</h4>
            <p style="margin: 0; color: #7f8c8d; font-size: 12px; line-height: 1.5;">
                1. Payment is due within ' . ($data['invoice']['due_days'] ?? 30) . ' days of invoice date.<br>
                2. Late payments may incur a penalty of ' . ($data['invoice']['late_fee_percentage'] ?? 5) . '% per month.<br>
                3. Please include invoice number with payment.<br>
                4. Receipts will be issued upon payment confirmation.<br>
                5. For questions, contact ' . ($data['school']['email'] ?? 'accounts@academixsuite.com') . '
            </p>
        </div>';
        
        $this->pdf->WriteHTML($html);
    }
    
    /**
     * Generate invoice footer
     */
    private function generateInvoiceFooter(array $data): void {
        $html = '
        <table width="100%" style="border-top: 2px solid #333; padding-top: 20px; margin-top: 30px;">
            <tr>
                <td style="text-align: center; color: #7f8c8d; font-size: 12px;">
                    <p style="margin: 5px 0;">
                        Thank you for your business!<br>
                        ' . ($data['school']['name'] ?? 'AcademixSuite') . ' | ' . ($data['school']['phone'] ?? '') . ' | ' . ($data['school']['email'] ?? '') . '
                    </p>
                    <p style="margin: 5px 0; font-size: 10px;">
                        This is a computer-generated document. No signature required.
                    </p>
                </td>
            </tr>
        </table>';
        
        $this->pdf->WriteHTML($html);
    }
    
    /**
     * Generate receipt header
     */
    private function generateReceiptHeader(array $data): void {
        $html = '
        <table width="100%" style="border-bottom: 2px solid #27ae60; margin-bottom: 20px;">
            <tr>
                <td width="50%">
                    <h1 style="color: #2c3e50; margin: 0;">' . ($data['school']['name'] ?? 'AcademixSuite') . '</h1>
                    <p style="margin: 5px 0; color: #7f8c8d;">
                        ' . ($data['school']['address'] ?? '') . '<br>
                        Phone: ' . ($data['school']['phone'] ?? '') . '
                    </p>
                </td>
                <td width="50%" style="text-align: right;">
                    <h2 style="color: #27ae60; margin: 0; font-size: 24px;">PAYMENT RECEIPT</h2>
                    <p style="margin: 5px 0; color: #7f8c8d;">
                        Receipt #: ' . ($data['payment']['payment_number'] ?? '') . '<br>
                        Date: ' . date('F j, Y', strtotime($data['payment']['payment_date'] ?? 'now')) . '<br>
                        Time: ' . date('h:i A', strtotime($data['payment']['created_at'] ?? 'now')) . '
                    </p>
                </td>
            </tr>
        </table>';
        
        $this->pdf->WriteHTML($html);
    }
    
    /**
     * Generate receipt info
     */
    private function generateReceiptInfo(array $data): void {
        $html = '
        <table width="100%" style="margin-bottom: 20px;">
            <tr>
                <td width="50%" style="vertical-align: top;">
                    <h3 style="color: #2c3e50; margin: 0 0 10px 0;">Received From:</h3>
                    <p style="margin: 0; line-height: 1.5;">
                        <strong>' . ($data['parent']['name'] ?? '') . '</strong><br>
                        ' . ($data['parent']['address'] ?? '') . '<br>
                        Phone: ' . ($data['parent']['phone'] ?? '') . '<br>
                        Email: ' . ($data['parent']['email'] ?? '') . '
                    </p>
                </td>
                <td width="50%" style="vertical-align: top;">
                    <h3 style="color: #2c3e50; margin: 0 0 10px 0;">For Student:</h3>
                    <p style="margin: 0; line-height: 1.5;">
                        <strong>' . ($data['student']['first_name'] ?? '') . ' ' . ($data['student']['last_name'] ?? '') . '</strong><br>
                        Admission #: ' . ($data['student']['admission_number'] ?? '') . '<br>
                        Class: ' . ($data['student']['class_name'] ?? '') . '<br>
                        Invoice #: ' . ($data['invoice']['invoice_number'] ?? '') . '
                    </p>
                </td>
            </tr>
        </table>';
        
        $this->pdf->WriteHTML($html);
    }
    
    /**
     * Generate payment details
     */
    private function generatePaymentDetails(array $data): void {
        $html = '
        <div style="background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 30px;">
            <h3 style="color: #2c3e50; margin: 0 0 15px 0;">Payment Details</h3>
            
            <table width="100%" style="border-collapse: collapse;">
                <tr>
                    <td style="padding: 10px; border-bottom: 1px solid #ddd; font-weight: bold;">Description</td>
                    <td style="padding: 10px; border-bottom: 1px solid #ddd; text-align: right; font-weight: bold;">Amount</td>
                </tr>
                <tr>
                    <td style="padding: 10px; border-bottom: 1px solid #ddd;">Payment for Invoice #' . ($data['invoice']['invoice_number'] ?? '') . '</td>
                    <td style="padding: 10px; border-bottom: 1px solid #ddd; text-align: right;">' . 
                        $this->formatCurrency($data['payment']['amount'] ?? 0, $data['payment']['currency'] ?? 'NGN') . '</td>
                </tr>
                <tr style="background-color: #e8f6f3;">
                    <td style="padding: 10px; font-weight: bold;">Payment Method</td>
                    <td style="padding: 10px; text-align: right;">' . ($data['payment']['payment_method'] ?? '') . '</td>
                </tr>';
        
        if (!empty($data['payment']['transaction_id'])) {
            $html .= '
                <tr style="background-color: #e8f6f3;">
                    <td style="padding: 10px; font-weight: bold;">Transaction ID</td>
                    <td style="padding: 10px; text-align: right;">' . ($data['payment']['transaction_id'] ?? '') . '</td>
                </tr>';
        }
        
        if (!empty($data['payment']['reference'])) {
            $html .= '
                <tr style="background-color: #e8f6f3;">
                    <td style="padding: 10px; font-weight: bold;">Reference</td>
                    <td style="padding: 10px; text-align: right;">' . ($data['payment']['reference'] ?? '') . '</td>
                </tr>';
        }
        
        $html .= '
                <tr style="border-top: 2px solid #27ae60;">
                    <td style="padding: 15px 10px; font-weight: bold; font-size: 16px;">Amount Paid</td>
                    <td style="padding: 15px 10px; text-align: right; font-weight: bold; font-size: 16px; color: #27ae60;">' . 
                        $this->formatCurrency($data['payment']['amount'] ?? 0, $data['payment']['currency'] ?? 'NGN') . '</td>
                </tr>
            </table>
        </div>
        
        <div style="text-align: center; margin: 30px 0;">
            <div style="display: inline-block; padding: 10px 30px; background-color: #27ae60; color: white; border-radius: 25px; font-weight: bold;">
                PAYMENT CONFIRMED
            </div>
        </div>';
        
        $this->pdf->WriteHTML($html);
    }
    
    /**
     * Generate receipt footer
     */
    private function generateReceiptFooter(array $data): void {
        $html = '
        <table width="100%" style="border-top: 2px solid #27ae60; padding-top: 20px; margin-top: 30px;">
            <tr>
                <td style="text-align: center; color: #7f8c8d; font-size: 12px;">
                    <p style="margin: 5px 0;">
                        <strong>Payment Received By:</strong><br>
                        ' . ($data['collected_by']['name'] ?? 'System') . ' (' . ($data['school']['name'] ?? 'AcademixSuite') . ')
                    </p>
                    <p style="margin: 5px 0;">
                        Thank you for your payment!<br>
                        For any queries, please contact: ' . ($data['school']['email'] ?? 'accounts@academixsuite.com') . '
                    </p>
                    <p style="margin: 5px 0; font-size: 10px;">
                        This receipt is generated electronically and is valid without signature.
                    </p>
                </td>
            </tr>
        </table>';
        
        $this->pdf->WriteHTML($html);
    }
    
    /**
     * Format currency
     */
    private function formatCurrency(float $amount, string $currency = 'NGN'): string {
        $symbols = [
            'NGN' => '₦',
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€'
        ];
        
        $symbol = $symbols[$currency] ?? $currency;
        
        return $symbol . number_format($amount, 2);
    }
    
    /**
     * Generate financial report
     */
    private function generateFinancialReport(array $data): void {
        $html = '
        <h1 style="color: #2c3e50; text-align: center; margin-bottom: 30px;">Financial Report</h1>
        
        <table width="100%" style="margin-bottom: 30px;">
            <tr>
                <td style="text-align: center;">
                    <p>Period: ' . ($data['period']['start'] ?? '') . ' to ' . ($data['period']['end'] ?? '') . '</p>
                    <p>Generated on: ' . date('F j, Y') . ' at ' . date('h:i A') . '</p>
                </td>
            </tr>
        </table>';
        
        $this->pdf->WriteHTML($html);
        
        // Add more report content based on $data
    }
    
    /**
     * Generate attendance report
     */
    private function generateAttendanceReport(array $data): void {
        // Implementation for attendance report
    }
    
    /**
     * Generate academic report
     */
    private function generateAcademicReport(array $data): void {
        // Implementation for academic report
    }
    
    /**
     * Generate general report
     */
    private function generateGeneralReport(array $data): void {
        // Implementation for general report
    }
    
    /**
     * Generate certificate content
     */
    private function generateCertificateContent(array $data): void {
        // Implementation for certificate generation
    }
    
    /**
     * Generate ID card content
     */
    private function generateIdCardContent(array $data): void {
        // Implementation for ID card generation
    }
    
    /**
     * Save PDF to file
     */
    public function saveToFile(string $content, string $filename): bool {
        try {
            $saveDir = defined('PDF_SAVE_DIR') ? PDF_SAVE_DIR : __DIR__ . '/../../assets/uploads/pdfs/';
            
            if (!is_dir($saveDir)) {
                mkdir($saveDir, 0755, true);
            }
            
            $filepath = $saveDir . $filename;
            
            return file_put_contents($filepath, $content) !== false;
            
        } catch (\Exception $e) {
            error_log("Failed to save PDF: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Merge multiple PDFs
     */
    public function mergePDFs(array $pdfFiles, string $outputFilename): bool {
        try {
            $pdf = new \setasign\Fpdi\Fpdi();
            
            foreach ($pdfFiles as $file) {
                if (file_exists($file)) {
                    $pageCount = $pdf->setSourceFile($file);
                    
                    for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                        $templateId = $pdf->importPage($pageNo);
                        $size = $pdf->getTemplateSize($templateId);
                        
                        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                        $pdf->useTemplate($templateId);
                    }
                }
            }
            
            $saveDir = defined('PDF_SAVE_DIR') ? PDF_SAVE_DIR : __DIR__ . '/../../assets/uploads/pdfs/';
            $outputPath = $saveDir . $outputFilename;
            
            $pdf->Output($outputPath, 'F');
            
            return true;
            
        } catch (\Exception $e) {
            error_log("Failed to merge PDFs: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Add watermark to PDF
     */
    public function addWatermark(string $inputFile, string $outputFile, string $watermarkText = 'CONFIDENTIAL'): bool {
        try {
            $pdf = new \setasign\Fpdi\Fpdi();
            
            $pageCount = $pdf->setSourceFile($inputFile);
            
            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);
                
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($templateId);
                
                // Add watermark
                $pdf->SetFont('Arial', 'B', 50);
                $pdf->SetTextColor(200, 200, 200);
                $pdf->Rotate(45, 150, 210);
                $pdf->Text(50, 190, $watermarkText);
                $pdf->Rotate(0);
            }
            
            $saveDir = defined('PDF_SAVE_DIR') ? PDF_SAVE_DIR : __DIR__ . '/../../assets/uploads/pdfs/';
            $outputPath = $saveDir . $outputFile;
            
            $pdf->Output($outputPath, 'F');
            
            return true;
            
        } catch (\Exception $e) {
            error_log("Failed to add watermark: " . $e->getMessage());
            return false;
        }
    }
}