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
        'name' => get_translation( 'Reisedetails',$product_id ),
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

    function encode_for_tcpdf($text) {
        return mb_convert_encoding($text, 'UTF-8', 'auto'); // Ensure text is UTF-8 encoded
    }

    // Define properties
    $font_name = 'dejavusans';
    $lineheight = 1.5;             // Line height for cells
    $fontsize_header = 13;         // Font size for headers
    $fontsize_content = 10;        // Font size for content
    $shortdescription_textlength = 447; // Length of the short description
    $description_textlength = 1932;     // Length of the full description
    $image_width = 50;             // Width for images in the PDF
    $image_height = 50;            // Height for images in the PDF

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
        $pdf->SetAuthor('Tamino Travel');
        $pdf->SetTitle(esc_html($product->get_title()));
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(TRUE, 10);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);

        $pdf->AddPage();
        $pdf->SetFont($font_name, '', $fontsize_header);

        // **STEP 1: TITLE ONLY**
        error_log("📄 Adding title...");
        $pdf->Cell(0, $lineheight * 10, esc_html($product->get_title()), 0, 1, 'C');
        $pdf->Ln(5);

        // **STEP 2: IMAGE AND SHORT DESCRIPTION HANDLING**
        /*******************************************************************
         * ************************ TOUR IMAGE  ****************************
         *******************************************************************/
        error_log("🖼 Checking product image...");
        $image_id = $product->get_image_id();
        $short_description = $product->get_short_description(); // Get WooCommerce short description

        if ($image_id) {
            $image_url = wp_get_attachment_url($image_id);
            if ($image_url) {
                error_log("✅ Image found: " . $image_url);

                // Fetch image dimensions
                list($orig_width, $orig_height) = @getimagesize($image_url);
                $aspect_ratio = $orig_width / $orig_height;

                // Define image width & height while maintaining aspect ratio
                $image_width = 70; // Fixed width
                $image_height = $image_width / $aspect_ratio; // Adjust height dynamically

                // Insert Image at top-left corner
                $pdf->Image($image_url, 10, 30, $image_width, $image_height, '', '', 'L', false, 300, '', false, false, 0, false, false, false);

                // Define text width (remaining space after image)
                $text_x = $image_width + 20; // Position right of the image
                $text_width = 190 - $text_x; // Remaining space for text

                /*******************************************************************
                * ********************* SHORT DESCRIPTION  *************************
                *******************************************************************/    
                // Wrap short description next to image
                $pdf->SetXY($text_x, 30); // Move cursor beside image
                if (!empty($short_description)) {
                    error_log("📄 Adding short description...");
                    //error_log('Short descripton is: '.$short_description);
                    $pdf->SetFont($font_name, '', $fontsize_content);
                    //$short_description_html = '<p style="line-height: ' . $lineheight . ';">' . nl2br(esc_html(substr($short_description, 0, $shortdescription_textlength))) . '</p>';
                    $pdf->writeHTMLCell($text_width, 0, '', '', $short_description, 0, 1, 0, true, 'L', true);
                } else {
                    error_log("⚠️ No short description found.");
                }

                // Move cursor below the image
                $pdf->SetXY(10, 30 + $image_height + 10);
            } else {
                error_log("⚠️ Image ID exists but URL is missing!");
            }
        } else {
            error_log("⚠️ No image attached to product.");
        }


        /***********************************************************************
        ************************* GALLERY IMAGES *******************************
        ************************************************************************/
        // Retrieve WooCommerce gallery images
        $gallery_images = $product->get_gallery_image_ids();

        $image_height = 25;  // Fixed height for uniformity
        $x_position = 10;    // Starting X position for the first image
        $y_position = $pdf->GetY();  // Current Y position in the PDF
        $spacing = 5;        // Spacing between images
        $counter = 0;
        $max_images = 4;     // Limit to 3 images only

        // Check if gallery images are valid
        if (!empty($gallery_images) && is_array($gallery_images)) {
            foreach ($gallery_images as $image_id) {
                if ($counter >= $max_images) break; // Stop after 3 images

                $image_url = wp_get_attachment_url($image_id);
                $image_path = get_attached_file($image_id);

                if ($image_url && file_exists($image_path)) {
                    // Get original image dimensions
                    list($original_width, $original_height) = getimagesize($image_path);

                    if ($original_width > 0 && $original_height > 0) {
                        // Maintain aspect ratio
                        $image_width = ($original_width / $original_height) * $image_height;

                        // Place the image in the PDF
                        $pdf->Image($image_url, $x_position, $y_position, $image_width, $image_height);

                        // Update X position for the next image
                        $x_position += $image_width + $spacing;
                        $counter++;
                    } else {
                        error_log("⚠️ Invalid image dimensions for ID: " . $image_id);
                    }
                } else {
                    error_log("⚠️ Image file not found: " . $image_url);
                }
            }

            // Insert a new line after images
            $pdf->Ln($image_height + 5);
        } else {
            error_log("⚠️ No gallery images found for this product.");
        }



        /*******************************************************************
         * *********************** DESCRIPTION  ****************************
         *******************************************************************/    
        // **STEP 3: DESCRIPTION**
        error_log("📝 Adding product description...");
        $pdf->SetFont($font_name, '', $fontsize_content);
        //$description = substr(esc_html($product->get_description()), 0, $description_textlength);
        $description_plain = $product->get_description();
        //error_log("Description is: ".$description_plain);


        //$pdf->MultiCell(0, $lineheight * 5, $description_plain, 0, 'L');
        //$pdf->Ln(10);


        $plain_text_content_html = '<p style="line-height:'.$lineheight.';">'.nl2br(encode_for_tcpdf(substr($description_plain, 0, $description_textlength))).'</p>';
        $pdf->writeHTMLCell(0, 0, '', '', $plain_text_content_html, 0, 1, 0, true, 'L', true);
        $pdf->Ln(10);
        /*******************************************************************
         * ************************* ORTE  *********************************
         *******************************************************************/            
        // Retrieve "Orte" from WooCommerce product meta
        $orte = maybe_unserialize(get_post_meta($product_id, '_tamino_travel_orte', true));

        if (is_array($orte) && count($orte) > 0) {
            // Add a new page if needed
            $pdf->AddPage();

            // Title for the section
            $pdf->SetFont($font_name, 'B', $fontsize_header);
            $pdf->Cell(0, 10, get_translation( 'Diese Orte werden sie sehen',$product_id ), 0, 1, 'C'); // Centered title
            $pdf->Ln(5);

            foreach ($orte as $station_id) {
                // Retrieve station post
                $station_post = get_post($station_id);

                if ($station_post) {
                    // Get station title and content
                    $station_title = encode_for_tcpdf($station_post->post_title);
                    $station_content = encode_for_tcpdf(trim(preg_replace('/\s+/', ' ', strip_tags($station_post->post_content))));
                    $station_content_without_tags = strip_tags($station_post->post_content);
                    $station_content_final = substr($station_content, 0, 600) . '...'; // Limit text length

                    // Get station image
                    $station_image_id = get_post_thumbnail_id($station_id);
                    $station_image_url = wp_get_attachment_url($station_image_id);

                    // Define fixed width
                    $image_width = 40; // Fixed width
                    $image_height = 0; // Height will be calculated dynamically

                    if ($station_image_url) {
                        // Get image dimensions
                        $image_info = getimagesize($station_image_url);
                        if ($image_info) {
                            $original_width = $image_info[0];
                            $original_height = $image_info[1];

                            // Calculate new height based on aspect ratio
                            $image_height = ($original_height / $original_width) * $image_width;
                        }
                    }

                    // Image & Text Placement Variables
                    $image_x = 10;   // Left margin for image
                    $image_y = $pdf->GetY(); // Get current position for alignment
                    $text_x = $image_x + $image_width + 10; // Text starts after image
                    $text_width = 190 - $text_x; // Remaining width for text

                    // Display image if available
                    if ($station_image_url && $image_height > 0) {
                        $pdf->Image($station_image_url, $image_x, $image_y, $image_width, $image_height);
                    }

                    // Display title next to the image
                    $pdf->SetXY($text_x, $image_y);
                    $pdf->SetFont($font_name, 'B', $fontsize_content);
                    $pdf->Cell($text_width, 6, $station_title, 0, 1); // No extra line break

                    // Display description immediately after title
                    $pdf->SetXY($text_x, $image_y + 7); // Moves text directly below title
                    $pdf->SetFont($font_name, '', $fontsize_content);
                    $pdf->MultiCell($text_width, 6, $station_content_final, 0, 'L');

                    // Move down a bit before adding the separator
                    $pdf->Ln(5);
 
                    // Move cursor below the section
                    $pdf->Ln(10);
                }
            }
        }


        /*******************************************************************
         * ************************* STATIONEN  *****************************
         *******************************************************************/
        // Retrieve "Stationen" from WooCommerce product meta
        $stationen = maybe_unserialize(get_post_meta($product_id, '_tour_stations', true));
        if (is_array($stationen) && !empty($stationen)) {
            // Add a new page if needed
            $pdf->AddPage();
            
            // Title for the section
            $pdf->SetFont($font_name, 'B', $fontsize_header);

            

            $pdf->Cell(0, 10, get_translation( 'Stationen Ihrer Tour',$product_id ), 0, 1, 'C'); // Centered title
                              
            $pdf->Ln(5);
            $counter = 0;
            $total_stations = count($stationen);
            
            foreach ($stationen as $index => $station) {
                $current_y = $pdf->GetY();
                
                // Get station data
                $station_title = $station['title'] ?? 'No Title';
                
                // Add prefix for paid content
                if (!empty($station['payed_content']) && $station['payed_content'] == 1) {
                    $station_title = get_translation( 'Entdecker Tipp',$product_id ) .": ". $station_title;
                }
                
                $station_title = encode_for_tcpdf($station_title);
                $station_content = encode_for_tcpdf(strip_tags($station['description'] ?? ''));
                $station_images = $station['gallery'] ?? [];

                // Display title
                $pdf->SetFont($font_name, 'B', $fontsize_content);
                $pdf->Cell(0, 6, $station_title, 0, 1, 'L');

                $pdf->Ln(5); // Add some space before description
                
                // Display description
                $pdf->SetFont($font_name, '', $fontsize_content);
                $pdf->SetX(10); // Reset X to left margin
                $pdf->MultiCell(190, 6, $station_content, 0, 'L'); // Full width for description
                $pdf->Ln(5); // Add some space before images

                // Handle station images if available
                if (!empty($station_images) && is_array($station_images)) {
                    $uniform_height = 30; // Set uniform height for all images
                    $max_images = 3; // Limit to 3 images
                    $margin_left = 10; // Left margin
                    $available_width = 190; // Available width for images
                    
                    // Calculate spacing between images based on available width
                    $total_images = min(count($station_images), $max_images);
                    $image_spacing = ($available_width - ($total_images * ($uniform_height * 1.5))) / ($total_images + 1);
                    
                    $image_count = 0;
                    $row_y = $pdf->GetY();

                    // Take only the first 3 images
                    $limited_images = array_slice($station_images, 0, $max_images);
                    
                    foreach ($limited_images as $image_id) {
                        $image_url = wp_get_attachment_url($image_id);
                        
                        if ($image_url) {
                            // Get image dimensions
                            $image_info = getimagesize($image_url);
                            if ($image_info) {
                                $original_width = $image_info[0];
                                $original_height = $image_info[1];
                                
                                // Calculate width based on uniform height while maintaining aspect ratio
                                $image_width = ($original_width / $original_height) * $uniform_height;
                                
                                // Calculate x position with even spacing
                                $image_x = $margin_left + ($image_spacing * ($image_count + 1)) + ($image_width * $image_count);
                                
                                // Add image to PDF
                                $pdf->Image($image_url, $image_x, $row_y, $image_width, $uniform_height);
                                
                                $image_count++;
                            }
                        }
                    }
                    
                    // Move cursor below images if there were any
                    if ($image_count > 0) {
                        $pdf->SetY($row_y + $uniform_height);
                    }
                }

                // Add spacing before next station
                $pdf->Ln(15);

                ++$counter;
                
                // Only add a new page if we're not at the last station and counter has reached 2
                if($counter == 2 && $index < $total_stations - 1) {
                    $pdf->AddPage();
                    $counter = 0;
                }
            }
        }


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

/** THIS WOUL DO SOMETHING ON SAVE */
//add_action('woocommerce_process_product_meta', 'auto_generate_product_pdf_on_save');

/**
 * Get the translation of a string with Polylang, ensuring proper initialization.
 *
 * @param string $string The string to be translated.
 * @param string $context (optional) The context/category for Polylang string registration.
 * @param string|null $language (optional) The language code (e.g., 'de', 'en'). If null, it tries to detect it.
 * @return string Translated string or original if no translation exists.
 */
function get_translation($string, $product_id) {
    error_log("get_translation() called with string: " . $string);
                   
    $language = pll_get_post_language($product_id);
    error_log("Detected language from product (ID: $product_id): " . ($language ? $language : "NULL"));

    // Attempt translation - corrected parameter order
    $translated_string = pll_translate_string($string, $language, 'taminotravel');
    if ($translated_string === $string) {
        error_log("No translation found for string: " . $string);
    } else {
        error_log("Translation found: " . $translated_string);
    }
    return $translated_string;
}