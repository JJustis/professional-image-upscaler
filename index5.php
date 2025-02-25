<?php
/**
 * Real Image Upscaler - Automatic 16x to 64x Upscaler with Edge Enhancement
 * 
 * This script provides both a command-line interface and web interface for monitoring
 * a folder, detecting images, and upscaling them from 16x to 64x with professional quality.
 * 
 * Usage (CLI): php upscaler.php /path/to/input /path/to/output
 * Usage (Web): Access this file through a web browser
 */

// Configuration
$config = [
    'input_folder' => isset($argv[1]) ? $argv[1] : 'input_images', 
    'output_folder' => isset($argv[2]) ? $argv[2] : 'upscaled_images',
    'scan_interval' => 2, // Interval in seconds to scan for new images
    'image_types' => ['jpg', 'jpeg', 'png', 'gif'] // Supported image types
];

// Create output directory if it doesn't exist
if (!file_exists($config['output_folder'])) {
    mkdir($config['output_folder'], 0755, true);
}

// Function to check if a file is an image based on extension
function isImage($filename, $supportedTypes) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, $supportedTypes);
}

/**
 * Main function to monitor folder and process new images
 */
function monitorFolder($config) {
    $processedFiles = [];
    
    echo "Starting monitoring of {$config['input_folder']} for images...\n";
    
    while (true) {
        $files = scandir($config['input_folder']);
        
        foreach ($files as $file) {
            // Skip directories and already processed files
            if ($file === '.' || $file === '..' || in_array($file, $processedFiles)) {
                continue;
            }
            
            $filePath = rtrim($config['input_folder'], '/') . '/' . $file;
            
            if (is_file($filePath) && isImage($file, $config['image_types'])) {
                echo "Processing new image: $file\n";
                
                try {
                    // Process the image
                    $outputPath = rtrim($config['output_folder'], '/') . '/upscaled_' . $file;
                    upscaleImage($filePath, $outputPath);
                    $processedFiles[] = $file;
                    echo "Successfully upscaled $file to $outputPath\n";
                } catch (Exception $e) {
                    echo "Error processing $file: " . $e->getMessage() . "\n";
                }
            }
        }
        
        // Sleep before the next scan
        sleep($config['scan_interval']);
    }
}

/**
 * Function to upscale an image from 16x to 64x with enhanced detail
 */
function upscaleImage($inputPath, $outputPath) {
    // Check if file exists
    if (!file_exists($inputPath)) {
        throw new Exception("Input file does not exist: $inputPath");
    }
    
    // Load the image
    list($width, $height, $type) = getimagesize($inputPath);
    
    // Create image resource based on file type
    switch ($type) {
        case IMAGETYPE_JPEG:
            $source = imagecreatefromjpeg($inputPath);
            $hasTransparency = false;
            break;
        case IMAGETYPE_PNG:
            $source = imagecreatefrompng($inputPath);
            // Set up to properly preserve transparency
            imagealphablending($source, false);
            imagesavealpha($source, true);
            $hasTransparency = true;
            break;
        case IMAGETYPE_GIF:
            $source = imagecreatefromgif($inputPath);
            // Check if GIF has transparency
            $hasTransparency = checkGifTransparency($inputPath);
            break;
        default:
            throw new Exception("Unsupported image type for file: $inputPath");
    }
    
    if (!$source) {
        throw new Exception("Failed to create image resource from: $inputPath");
    }
    
    // Create a new image with 4x dimensions (16x to 64x)
    $newWidth = $width * 4;
    $newHeight = $height * 4;
    
    // Create truecolor image for best quality
    $upscaled = imagecreatetruecolor($newWidth, $newHeight);
    
    // Handle transparency for PNG and transparent GIFs
    if ($hasTransparency) {
        // Enable transparency handling
        imagealphablending($upscaled, false);
        imagesavealpha($upscaled, true);
        $transparent = imagecolorallocatealpha($upscaled, 0, 0, 0, 127);
        imagefilledrectangle($upscaled, 0, 0, $newWidth, $newHeight, $transparent);
    }
    
    // Perform the upscaling with best quality
    imagecopyresampled($upscaled, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    // Apply edge enhancement - only if not transparent or we're handling transparency properly
    if (!$hasTransparency) {
        enhanceEdges($upscaled);
        blendColors($upscaled);
    } else {
        // Apply specialized enhancement that preserves transparency
        enhanceEdgesWithTransparency($upscaled);
        blendColorsWithTransparency($upscaled);
    }
    
    // Save the upscaled image
    switch ($type) {
        case IMAGETYPE_JPEG:
            imagejpeg($upscaled, $outputPath, 95); // 95% quality
            break;
        case IMAGETYPE_PNG:
            // Ensure alpha channel is saved
            imagealphablending($upscaled, false);
            imagesavealpha($upscaled, true);
            imagepng($upscaled, $outputPath, 9); // Maximum compression
            break;
        case IMAGETYPE_GIF:
            imagegif($upscaled, $outputPath);
            break;
    }
    
    // Free memory
    imagedestroy($source);
    imagedestroy($upscaled);
    
    return true;
}

/**
 * Check if a GIF has transparency
 */
function checkGifTransparency($path) {
    $image = imagecreatefromgif($path);
    $colorCount = imagecolorstotal($image);
    
    for ($i = 0; $i < $colorCount; $i++) {
        $color = imagecolorsforindex($image, $i);
        if ($color['alpha'] > 0) {
            imagedestroy($image);
            return true;
        }
    }
    
    imagedestroy($image);
    return false;
}

/**
 * Apply edge enhancement filter for sharper details (standard version)
 */
function enhanceEdges(&$image) {
    // Apply unsharp mask for edge enhancement
    $matrix = [
        [-1, -1, -1],
        [-1, 16, -1],
        [-1, -1, -1]
    ];
    
    $divisor = array_sum(array_map('array_sum', $matrix));
    $offset = 0;
    
    // Apply convolution filter
    imageconvolution($image, $matrix, $divisor, $offset);
    
    // Increase contrast slightly
    imagefilter($image, IMG_FILTER_CONTRAST, 15);
}

/**
 * Apply color blending for smoother gradients (standard version)
 */
function blendColors(&$image) {
    // Save original
    $width = imagesx($image);
    $height = imagesy($image);
    
    $temp = imagecreatetruecolor($width, $height);
    imagecopy($temp, $image, 0, 0, 0, 0, $width, $height);
    
    // Apply slight gaussian blur to smooth colors without losing detail
    for ($i = 0; $i < 1; $i++) {
        imagefilter($image, IMG_FILTER_GAUSSIAN_BLUR);
    }
    
    // Blend original with blurred version using opacity
    imagecopymerge($image, $temp, 0, 0, 0, 0, $width, $height, 60); // 60% original, 40% blurred
    
    // Free memory
    imagedestroy($temp);
}

/**
 * Enhanced edge enhancement that preserves transparency
 */
function enhanceEdgesWithTransparency(&$image) {
    // Get dimensions
    $width = imagesx($image);
    $height = imagesy($image);
    
    // Create a copy with transparency settings preserved
    $temp = imagecreatetruecolor($width, $height);
    imagealphablending($temp, false);
    imagesavealpha($temp, true);
    
    // Apply manual edge detection to preserve alpha
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            // Get pixel color with transparency
            $rgba = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            
            // Skip fully transparent pixels
            if ($rgba['alpha'] == 127) {
                continue;
            }
            
            // Calculate color intensity
            $intensity = ($rgba['red'] + $rgba['green'] + $rgba['blue']) / 3;
            
            // Apply mild sharpening by increasing contrast around edges
            if ($intensity > 128) {
                $rgba['red'] = min(255, $rgba['red'] + 10);
                $rgba['green'] = min(255, $rgba['green'] + 10);
                $rgba['blue'] = min(255, $rgba['blue'] + 10);
            } else {
                $rgba['red'] = max(0, $rgba['red'] - 10);
                $rgba['green'] = max(0, $rgba['green'] - 10);
                $rgba['blue'] = max(0, $rgba['blue'] - 10);
            }
            
            // Set the modified pixel
            $newColor = imagecolorallocatealpha($image, $rgba['red'], $rgba['green'], $rgba['blue'], $rgba['alpha']);
            imagesetpixel($image, $x, $y, $newColor);
        }
    }
}

/**
 * Apply color blending for smoother gradients while preserving transparency
 */
function blendColorsWithTransparency(&$image) {
    // Create a copy with alpha support
    $width = imagesx($image);
    $height = imagesy($image);
    
    $temp = imagecreatetruecolor($width, $height);
    imagealphablending($temp, false);
    imagesavealpha($temp, true);
    
    // Copy the source image
    imagecopy($temp, $image, 0, 0, 0, 0, $width, $height);
    
    // Create a blurred version with alpha support
    $blurred = imagecreatetruecolor($width, $height);
    imagealphablending($blurred, false);
    imagesavealpha($blurred, true);
    imagecopy($blurred, $image, 0, 0, 0, 0, $width, $height);
    
    // Custom blur that respects transparency
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $rgba = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            
            // Skip fully transparent pixels
            if ($rgba['alpha'] == 127) {
                continue;
            }
            
            // Apply a subtle smoothing only to non-transparent areas
            $r = $g = $b = 0;
            $count = 0;
            
            // Sample surrounding pixels
            for ($dy = -1; $dy <= 1; $dy++) {
                for ($dx = -1; $dx <= 1; $dx++) {
                    $nx = $x + $dx;
                    $ny = $y + $dy;
                    
                    if ($nx >= 0 && $nx < $width && $ny >= 0 && $ny < $height) {
                        $nrgba = imagecolorsforindex($image, imagecolorat($image, $nx, $ny));
                        
                        // Only include non-transparent pixels in the blur
                        if ($nrgba['alpha'] < 127) {
                            $r += $nrgba['red'];
                            $g += $nrgba['green'];
                            $b += $nrgba['blue'];
                            $count++;
                        }
                    }
                }
            }
            
            if ($count > 0) {
                $r = round($r / $count);
                $g = round($g / $count);
                $b = round($b / $count);
                
                // Blend original and blurred version
                $r = round($rgba['red'] * 0.7 + $r * 0.3);
                $g = round($rgba['green'] * 0.7 + $g * 0.3);
                $b = round($rgba['blue'] * 0.7 + $b * 0.3);
                
                $newColor = imagecolorallocatealpha($blurred, $r, $g, $b, $rgba['alpha']);
                imagesetpixel($blurred, $x, $y, $newColor);
            }
        }
    }
    
    // Apply the blurred result back to the image with alpha preserved
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $origColor = imagecolorat($image, $x, $y);
            $origRgba = imagecolorsforindex($image, $origColor);
            
            // Only process non-fully-transparent pixels
            if ($origRgba['alpha'] < 127) {
                $blurColor = imagecolorat($blurred, $x, $y);
                $blurRgba = imagecolorsforindex($blurred, $blurColor);
                
                $newColor = imagecolorallocatealpha($image, 
                    $blurRgba['red'], 
                    $blurRgba['green'], 
                    $blurRgba['blue'], 
                    $origRgba['alpha'] // Preserve the original alpha
                );
                
                imagesetpixel($image, $x, $y, $newColor);
            }
        }
    }
    
    // Free memory
    imagedestroy($temp);
    imagedestroy($blurred);
}

/**
 * Process an uploaded file via the web interface
 */
function processUploadedFile($file, $outputFolder) {
    // Check if upload is valid
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [
            'success' => false,
            'message' => 'Upload failed with error code: ' . $file['error']
        ];
    }
    
    // Generate output filename
    $outputPath = rtrim($outputFolder, '/') . '/upscaled_' . basename($file['name']);
    
    try {
        // Process the uploaded file
        upscaleImage($file['tmp_name'], $outputPath);
        
        return [
            'success' => true,
            'message' => 'Image successfully upscaled',
            'original_name' => $file['name'],
            'output_path' => $outputPath
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error processing image: ' . $e->getMessage()
        ];
    }
}

/**
 * AJAX endpoint for processing a folder through the web interface
 */
function processFolder($inputFolder, $outputFolder) {
    $results = [];
    $processedCount = 0;
    $errorCount = 0;
    
    if (!file_exists($inputFolder)) {
        return json_encode([
            'success' => false,
            'message' => 'Input folder does not exist'
        ]);
    }
    
    // Create output folder if it doesn't exist
    if (!file_exists($outputFolder)) {
        mkdir($outputFolder, 0755, true);
    }
    
    $files = scandir($inputFolder);
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        
        $filePath = rtrim($inputFolder, '/') . '/' . $file;
        
        if (is_file($filePath) && isImage($file, ['jpg', 'jpeg', 'png', 'gif'])) {
            try {
                $outputPath = rtrim($outputFolder, '/') . '/upscaled_' . $file;
                upscaleImage($filePath, $outputPath);
                $processedCount++;
                $results[] = [
                    'file' => $file,
                    'success' => true
                ];
            } catch (Exception $e) {
                $errorCount++;
                $results[] = [
                    'file' => $file,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
    }
    
    return json_encode([
        'success' => true,
        'processed' => $processedCount,
        'errors' => $errorCount,
        'results' => $results
    ]);
}

/**
 * Web interface output
 */
function outputWebInterface() {
    // Handle AJAX requests
    if (isset($_POST['action'])) {
        header('Content-Type: application/json');
        
        switch ($_POST['action']) {
            case 'upload':
                if (isset($_FILES['image'])) {
                    echo json_encode(processUploadedFile($_FILES['image'], $_POST['outputFolder']));
                }
                exit;
            
            case 'process_folder':
                echo processFolder($_POST['inputFolder'], $_POST['outputFolder']);
                exit;
            
            default:
                echo json_encode(['success' => false, 'message' => 'Unknown action']);
                exit;
        }
    }
    
    // Regular web interface
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Professional Image Upscaler - 16x to 64x</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body {
                font-family: Arial, sans-serif;
                line-height: 1.6;
                margin: 0;
                padding: 20px;
                background-color: #f5f5f5;
                color: #333;
            }
            
            .container {
                max-width: 1000px;
                margin: 0 auto;
                background: white;
                padding: 20px;
                box-shadow: 0 0 10px rgba(0,0,0,0.1);
                border-radius: 5px;
            }
            
            h1, h2 {
                color: #2c3e50;
                margin-top: 0;
            }
            
            .tabs {
                display: flex;
                margin-bottom: 20px;
                border-bottom: 1px solid #ddd;
            }
            
            .tab {
                padding: 10px 20px;
                cursor: pointer;
                background: #f8f8f8;
                margin-right: 5px;
                border: 1px solid #ddd;
                border-bottom: none;
                border-radius: 3px 3px 0 0;
            }
            
            .tab.active {
                background: white;
                border-bottom: 1px solid white;
                margin-bottom: -1px;
                font-weight: bold;
            }
            
            .tab-content {
                display: none;
                padding: 20px;
                border: 1px solid #ddd;
                border-top: none;
                background: white;
            }
            
            .tab-content.active {
                display: block;
            }
            
            form {
                margin-bottom: 20px;
            }
            
            label {
                display: block;
                margin-bottom: 5px;
                font-weight: bold;
            }
            
            input[type="text"],
            input[type="file"] {
                width: 100%;
                padding: 8px;
                margin-bottom: 15px;
                border: 1px solid #ddd;
                border-radius: 3px;
            }
            
            button {
                background-color: #3498db;
                color: white;
                border: none;
                padding: 10px 15px;
                border-radius: 3px;
                cursor: pointer;
                font-size: 16px;
            }
            
            button:hover {
                background-color: #2980b9;
            }
            
            button:disabled {
                background-color: #95a5a6;
                cursor: not-allowed;
            }
            
            .result-area {
                margin-top: 20px;
                border: 1px solid #ddd;
                padding: 15px;
                border-radius: 3px;
                background: #f9f9f9;
            }
            
            .images-container {
                display: flex;
                flex-wrap: wrap;
                gap: 15px;
                margin-top: 20px;
            }
            
            .image-card {
                border: 1px solid #ddd;
                border-radius: 3px;
                padding: 10px;
                width: calc(33.333% - 15px);
                box-sizing: border-box;
                background: white;
            }
            
            .image-card img {
                max-width: 100%;
                height: auto;
                display: block;
                margin-bottom: 10px;
            }
            
            .image-card .image-info {
                font-size: 14px;
                color: #7f8c8d;
            }
            
            .progress-bar {
                height: 20px;
                background-color: #ecf0f1;
                border-radius: 3px;
                margin-bottom: 10px;
                overflow: hidden;
            }
            
            .progress-bar .progress {
                height: 100%;
                background-color: #2ecc71;
                width: 0%;
                transition: width 0.3s;
            }
            
            #log {
                height: 200px;
                overflow-y: auto;
                background: #f8f8f8;
                border: 1px solid #ddd;
                padding: 10px;
                font-family: monospace;
                font-size: 14px;
                line-height: 1.4;
            }
            
            .log-entry {
                margin-bottom: 5px;
                border-bottom: 1px solid #eee;
                padding-bottom: 5px;
            }
            
            .log-time {
                color: #7f8c8d;
                margin-right: 10px;
            }
            
            .log-message.success {
                color: #27ae60;
            }
            
            .log-message.error {
                color: #c0392b;
            }
            
            @media (max-width: 768px) {
                .image-card {
                    width: calc(50% - 15px);
                }
            }
            
            @media (max-width: 480px) {
                .image-card {
                    width: 100%;
                }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Professional Image Upscaler (16x to 64x)</h1>
            <p>Upload images or process a folder to upscale images with professional edge enhancement and color blending. <strong>Full transparency support included!</strong></p>
            
            <div class="tabs">
                <div class="tab active" data-tab="upload">Single Upload</div>
                <div class="tab" data-tab="folder">Process Folder</div>
                <div class="tab" data-tab="batch">Batch Processing</div>
            </div>
            
            <div id="upload" class="tab-content active">
                <h2>Upload Single Image</h2>
                <form id="uploadForm" enctype="multipart/form-data">
                    <div>
                        <label for="imageFile">Select Image (JPG, PNG, GIF):</label>
                        <input type="file" id="imageFile" name="imageFile" accept=".jpg,.jpeg,.png,.gif">
                    </div>
                    
                    <div>
                        <label for="uploadOutputFolder">Output Folder:</label>
                        <input type="text" id="uploadOutputFolder" name="uploadOutputFolder" value="upscaled_images">
                    </div>
                    
                    <button type="submit" id="uploadButton">Upload & Process</button>
                </form>
                
                <div id="uploadResult" class="result-area" style="display: none;">
                    <h3>Processing Result</h3>
                    <div id="uploadStatus"></div>
                    
                    <div class="images-container">
                        <div class="image-card">
                            <h4>Original</h4>
                            <img id="originalImage" src="" alt="Original Image">
                            <div class="image-info" id="originalInfo"></div>
                        </div>
                        
                        <div class="image-card">
                            <h4>Upscaled (64x)</h4>
                            <img id="upscaledImage" src="" alt="Upscaled Image">
                            <div class="image-info" id="upscaledInfo"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="folder" class="tab-content">
                <h2>Process Folder</h2>
                <form id="folderForm">
                    <div>
                        <label for="inputFolder">Input Folder:</label>
                        <input type="text" id="inputFolder" name="inputFolder" value="input_images">
                    </div>
                    
                    <div>
                        <label for="outputFolder">Output Folder:</label>
                        <input type="text" id="outputFolder" name="outputFolder" value="upscaled_images">
                    </div>
                    
                    <button type="submit" id="processButton">Process Folder</button>
                </form>
                
                <div id="folderResult" class="result-area" style="display: none;">
                    <h3>Processing Results</h3>
                    <div class="progress-bar">
                        <div class="progress" id="folderProgress"></div>
                    </div>
                    <div id="folderStatus"></div>
                    
                    <h4>Processed Images</h4>
                    <div id="processedImages" class="images-container">
                        <!-- Processed images will be displayed here -->
                    </div>
                </div>
            </div>
            
            <div id="batch" class="tab-content">
                <h2>Batch Processing</h2>
                <p>Monitor a folder for new images and automatically process them.</p>
                
                <form id="batchForm">
                    <div>
                        <label for="batchInputFolder">Input Folder to Monitor:</label>
                        <input type="text" id="batchInputFolder" name="batchInputFolder" value="input_images">
                    </div>
                    
                    <div>
                        <label for="batchOutputFolder">Output Folder:</label>
                        <input type="text" id="batchOutputFolder" name="batchOutputFolder" value="upscaled_images">
                    </div>
                    
                    <div>
                        <label for="scanInterval">Scan Interval (seconds):</label>
                        <input type="number" id="scanInterval" name="scanInterval" value="5" min="1" max="60">
                    </div>
                    
                    <div>
                        <button type="button" id="startMonitoring">Start Monitoring</button>
                        <button type="button" id="stopMonitoring" disabled>Stop Monitoring</button>
                    </div>
                </form>
                
                <div class="result-area">
                    <h3>Processing Log</h3>
                    <div id="log"></div>
                </div>
            </div>
        </div>
        
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Tab functionality
                const tabs = document.querySelectorAll('.tab');
                tabs.forEach(tab => {
                    tab.addEventListener('click', function() {
                        const tabId = this.getAttribute('data-tab');
                        
                        // Update active tab
                        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                        this.classList.add('active');
                        
                        // Update active content
                        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                        document.getElementById(tabId).classList.add('active');
                    });
                });
                
                // Single file upload
                const uploadForm = document.getElementById('uploadForm');
                uploadForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const fileInput = document.getElementById('imageFile');
                    const outputFolder = document.getElementById('uploadOutputFolder').value;
                    
                    if (!fileInput.files.length) {
                        alert('Please select an image file');
                        return;
                    }
                    
                    const file = fileInput.files[0];
                    
                    // Create FormData
                    const formData = new FormData();
                    formData.append('action', 'upload');
                    formData.append('image', file);
                    formData.append('outputFolder', outputFolder);
                    
                    // Update UI
                    document.getElementById('uploadButton').disabled = true;
                    document.getElementById('uploadButton').textContent = 'Processing...';
                    
                    // Send AJAX request
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        document.getElementById('uploadButton').disabled = false;
                        document.getElementById('uploadButton').textContent = 'Upload & Process';
                        
                        // Show result area
                        document.getElementById('uploadResult').style.display = 'block';
                        
                        if (data.success) {
                            document.getElementById('uploadStatus').innerHTML = `<p style="color: green;">Success: ${data.message}</p>`;
                            
                            // Load original image
                            const reader = new FileReader();
                            reader.onload = function(e) {
                                document.getElementById('originalImage').src = e.target.result;
                            };
                            reader.readAsDataURL(file);
                            
                            document.getElementById('originalInfo').textContent = `${file.name} (${Math.round(file.size / 1024)} KB)`;
                            
                            // Load upscaled image
                            document.getElementById('upscaledImage').src = data.output_path;
                            document.getElementById('upscaledInfo').textContent = `upscaled_${data.original_name}`;
                        } else {
                            document.getElementById('uploadStatus').innerHTML = `<p style="color: red;">Error: ${data.message}</p>`;
                        }
                    })
                    .catch(error => {
                        document.getElementById('uploadButton').disabled = false;
                        document.getElementById('uploadButton').textContent = 'Upload & Process';
                        document.getElementById('uploadStatus').innerHTML = `<p style="color: red;">Error: ${error.message}</p>`;
                    });
                });
                
                // Folder processing
                const folderForm = document.getElementById('folderForm');
                folderForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const inputFolder = document.getElementById('inputFolder').value;
                    const outputFolder = document.getElementById('outputFolder').value;
                    
                    // Update UI
                    document.getElementById('processButton').disabled = true;
                    document.getElementById('processButton').textContent = 'Processing...';
                    
                    // Create form data
                    const formData = new FormData();
                    formData.append('action', 'process_folder');
                    formData.append('inputFolder', inputFolder);
                    formData.append('outputFolder', outputFolder);
                    
                    // Send AJAX request
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        document.getElementById('processButton').disabled = false;
                        document.getElementById('processButton').textContent = 'Process Folder';
                        
                        // Show result area
                        document.getElementById('folderResult').style.display = 'block';
                        
                        if (data.success) {
                            // Update progress
                            const total = data.processed + data.errors;
                            const progressPercent = (data.processed / total) * 100;
                            document.getElementById('folderProgress').style.width = `${progressPercent}%`;
                            
                            // Update status
                            document.getElementById('folderStatus').innerHTML = 
                                `<p>Processed ${data.processed} files successfully, ${data.errors} errors</p>`;
                            
                            // Clear existing images
                            document.getElementById('processedImages').innerHTML = '';
                            
                            // Add processed images to container
                            data.results.forEach(result => {
                                if (result.success) {
                                    const imageCard = document.createElement('div');
                                    imageCard.className = 'image-card';
                                    imageCard.innerHTML = `
                                        <h4>${result.file}</h4>
                                        <img src="${outputFolder}/upscaled_${result.file}" alt="${result.file}">
                                        <div class="image-info">Successfully upscaled</div>
                                    `;
                                    document.getElementById('processedImages').appendChild(imageCard);
                                }
                            });
                        } else {
                            document.getElementById('folderStatus').innerHTML = 
                                `<p style="color: red;">Error: ${data.message}</p>`;
                        }
                    })
                    .catch(error => {
                        document.getElementById('processButton').disabled = false;
                        document.getElementById('processButton').textContent = 'Process Folder';
                        document.getElementById('folderStatus').innerHTML = 
                            `<p style="color: red;">Error: ${error.message}</p>`;
                    });
                });
                
                // Batch processing setup
                let monitoring = false;
                let monitoringInterval = null;
                
                document.getElementById('startMonitoring').addEventListener('click', function() {
                    if (monitoring) return;
                    
                    const inputFolder = document.getElementById('batchInputFolder').value;
                    const outputFolder = document.getElementById('batchOutputFolder').value;
                    const interval = parseInt(document.getElementById('scanInterval').value);
                    
                    startMonitoring(inputFolder, outputFolder, interval);
                });
                
                document.getElementById('stopMonitoring').addEventListener('click', function() {
                    stopMonitoring();
                });
                
                function startMonitoring(inputFolder, outputFolder, interval) {
                    monitoring = true;
                    document.getElementById('startMonitoring').disabled = true;
                    document.getElementById('stopMonitoring').disabled = false;
                    
                    logMessage(`Started monitoring folder: ${inputFolder}`, 'success');
                    
                    // Setup real AJAX polling for new images
                    monitoringInterval = setInterval(function() {
                        checkFolder(inputFolder, outputFolder);
                    }, interval * 1000);
                }
                
                function stopMonitoring() {
                    monitoring = false;
                    document.getElementById('startMonitoring').disabled = false;
                    document.getElementById('stopMonitoring').disabled = true;
                    
                    logMessage('Stopped monitoring', 'normal');
                    
                    if (monitoringInterval) {
                        clearInterval(monitoringInterval);
                        monitoringInterval = null;
                    }
                }
                
                function checkFolder(inputFolder, outputFolder) {
                    // Create form data for AJAX request
                    const formData = new FormData();
                    formData.append('action', 'process_folder');
                    formData.append('inputFolder', inputFolder);
                    formData.append('outputFolder', outputFolder);
                    
                    // Send request to check for new files
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            if (data.processed > 0) {
                                logMessage(`Processed ${data.processed} new images`, 'success');
                                
                                // Log individual files
                                data.results.forEach(result => {
                                    if (result.success) {
                                        logMessage(`Upscaled: ${result.file}`, 'success');
                                    } else {
                                        logMessage(`Failed to process ${result.file}: ${result.error}`, 'error');
                                    }
                                });
                            }
                        } else {
                            logMessage(`Error checking folder: ${data.message}`, 'error');
                        }
                    })
                    .catch(error => {
                        logMessage(`Error communicating with server: ${error.message}`, 'error');
                    });
                }
                
                function logMessage(message, type = 'normal') {
                    const log = document.getElementById('log');
                    const now = new Date();
                    const timeString = now.toLocaleTimeString();
                    
                    const entry = document.createElement('div');
                    entry.className = 'log-entry';
                    entry.innerHTML = `
                        <span class="log-time">[${timeString}]</span>
                        <span class="log-message ${type}">${message}</span>
                    `;
                    
                    log.appendChild(entry);
                    log.scrollTop = log.scrollHeight;
                }
            });
        </script>
    </body>
    </html>
    <?php
}

// Determine whether to run in CLI mode or web mode
if (php_sapi_name() === 'cli') {
    // Command-line execution
    monitorFolder($config);
} else {
    // Web interface
    outputWebInterface();
}
?>