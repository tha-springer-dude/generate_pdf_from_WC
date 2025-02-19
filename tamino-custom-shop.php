<?php
/*
Plugin Name: Tamino Generate PDF for WooCommerce Product
Description: Creates a PDF from a WooCommerce product and attaches it as a downloadable file.
Version: 1.1
Author: Tamino Team
*/

function add_generate_pdf_button_to_acf($field) {
    global $post;

    // Ensure we are editing a WooCommerce product
    if (!$post || $post->post_type !== 'product') return;

    // Check if a PDF is already generated for this product
    $pdf_url = get_post_meta($post->ID, '_product_pdf', true);

    echo '<div class="generate-pdf-section" style="margin-top: 10px;">';

    if (!$pdf_url) {
        // Show "Generate PDF" button if no PDF exists
        $nonce = wp_create_nonce('generate_product_pdf_nonce');
        echo '<a href="' . admin_url('admin-post.php?action=generate_product_pdf&post_id=' . $post->ID . '&nonce=' . $nonce) . '" class="button button-primary">Generate PDF</a>';
    } else {
        // Show "Download PDF" link if PDF already exists
        echo '<p style="color:green;">PDF already generated. <a href="' . esc_url($pdf_url) . '" target="_blank">Download PDF</a></p>';
    }

    echo '</div>';
}

// Hook this function into ACF to display the button inside "Tour Options"
add_action('acf/render_field/key=field_6717611a38633', 'add_generate_pdf_button_to_acf');

function handle_create_product_from_tour() {
    // Verify the nonce for security.
    if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'generate_product_pdf_nonce')) {
        wp_die('Invalid request.'); // Exit if nonce verification fails
    }

    $post_id = intval($_GET['post_id']);

    // Call your existing function to create the WooCommerce product using the post ID.
    generate_product_pdf($post_id);

        // Fetch the latest saved PDF path from post meta
    $pdf_url = get_post_meta($post_id, '_product_pdf', true);

    if ($pdf_url) {
        // Attach the saved PDF URL to WooCommerce's downloadable files
        attach_pdf_to_product($post_id,$pdf_url);
    } else {
        error_log("❌ No PDF URL found in post meta after generation.");
    }

    //log_post_creation_status($post_id, 'Button clicked to create product.');

    // Redirect back to the edit screen with a success message.
    wp_redirect(admin_url('post.php?post=' . $post_id . '&action=edit&message=1'));
    exit;
}
// Hook to handle the admin post action for creating a WooCommerce product from a tour.
add_action('admin_post_generate_product_pdf', 'handle_create_product_from_tour');


function attach_pdf_to_product($product_id, $pdf_url) {
    if (!$product_id || !$pdf_url) {
        error_log("❌ attach_pdf_to_product() - Missing product ID or PDF URL");
        return;
    }

    // Load the WooCommerce product
    $product = wc_get_product($product_id);
    if (!$product) {
        error_log("❌ attach_pdf_to_product() - Product not found: " . $product_id);
        return;
    }

    // Get existing downloadable files
    $downloadables = $product->get_downloads();

    // Add the generated PDF
    $downloadables[] = array(
        'name' => 'Generated PDF',
        'file' => $pdf_url
    );

    // Update WooCommerce product
    $product->set_downloads($downloadables);
    $product->save();

    error_log("✅ PDF successfully attached to WooCommerce for product ID: " . $product_id);
}



require_once(plugin_dir_path( __FILE__ ) . 'tcpdf_src/tcpdf.php');


class CustomPDF extends TCPDF {
    // Empty Header method to avoid default behavior
    public function Header() {
        // No logo or content here
    }

    public function Footer() {
        // Set font for the footer
        $this->SetY(-15); // Position at 15 mm from bottom
        $this->SetFont('dejavusans', 'I', 8);

        // Total width of the page
        $pageWidth = $this->getPageWidth();

        // Website address on the left
        $this->SetX(10); // Position at 10 mm from the left
        $this->Cell(0, 10, 'www.tamino.travel', 0, 0, 'L');

        // Logo in the center
        $logo_path = get_stylesheet_directory() . '/logo_200.png';
        if (file_exists($logo_path)) {
            // Calculate the center position for the logo
            $logoWidth = 25; // Adjust the width of the logo as necessary
            $logoX = ($pageWidth - $logoWidth) / 2; // Center the logo
            $this->Image($logo_path, $logoX, $this->getY() + 0, $logoWidth, 0, '', '', '', false, 300, '', false, false, 0, false, false, false);
        } else {
            error_log("Logo file not found at: " . $logo_path);
        }

        // Page number on the right
        $this->SetX(-30); // Move cursor to 30 mm from the right edge
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}



function generate_product_pdf($product_id) {
    if (!$product_id) return false;

    error_log("🔍 Starting PDF generation for product ID: " . $product_id);

    $upload_dir = wp_upload_dir();
    $pdf_dir = $upload_dir['basedir'] . '/pdfs';

    if (!file_exists($pdf_dir)) {
        wp_mkdir_p($pdf_dir);
    }

    $product = wc_get_product($product_id);
    if (!$product) {
        error_log("❌ ERROR: Product not found for ID: " . $product_id);
        return false;
    }

    $sanitized_title = sanitize_file_name($product->get_title());
    $pdf_filename = 'product_' . $sanitized_title . '_' . time() . '.pdf';
    $pdf_path = $pdf_dir . '/' . $pdf_filename;
    $pdf_url = $upload_dir['baseurl'] . '/pdfs/' . $pdf_filename;

    try {
        error_log("✅ Initializing TCPDF...");
        $pdf = new CustomPDF();
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetTitle(esc_html($product->get_title()));
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(TRUE, 10);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 12);

        // **STEP 1: TITLE ONLY**
        error_log("📄 Adding title...");
        $pdf->Cell(0, 10, esc_html($product->get_title()), 0, 1, 'C');
        $pdf->Ln(5);

        // **STEP 2: IMAGE HANDLING**
        error_log("🖼 Checking product image...");
        $image_id = $product->get_image_id();
        if ($image_id) {
            $image_url = wp_get_attachment_url($image_id);
            if ($image_url) {
                error_log("✅ Image found: " . $image_url);
                $pdf->Image($image_url, 10, 30, 50);
                $pdf->Ln(50);
            } else {
                error_log("⚠️ Image ID exists but URL is missing!");
            }
        } else {
            error_log("⚠️ No image attached to product.");
        }

        // **STEP 3: DESCRIPTION**
        error_log("📝 Adding product description...");
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->MultiCell(0, 6, esc_html($product->get_short_description()), 0, 'L');
        $pdf->Ln(5);
        $pdf->MultiCell(0, 6, esc_html($product->get_description()), 0, 'L');
        $pdf->Ln(10);

        // **STEP 4: SAVE PDF**
        error_log("💾 Saving PDF...");
        $pdf->Output($pdf_path, 'F');

        // **STEP 5: ATTACH PDF TO PRODUCT**
        update_post_meta($product_id, '_product_pdf', $pdf_url);
        error_log("✅ PDF successfully created: " . $pdf_url);

        return $pdf_url;

    } catch (Exception $e) {
        error_log("❌ PDF Generation Error: " . $e->getMessage());
        return false;
    }
}

add_action('woocommerce_process_product_meta', 'auto_generate_product_pdf_on_save');
